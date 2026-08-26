<?php

namespace App\Http\Controllers;

use App\Models\DocketDeadline;
use App\Models\DeadlineRuleDefinition;
use App\Models\AuditLog;
use App\Models\Project;
use App\Support\DocketRules;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DocketController extends Controller
{
    /**
     * Events, deadlines and renewal schedule for one project's application.
     */
    public function show(Request $request, $projectId)
    {
        $project = Project::with('patentApplication.renewals')->findOrFail($projectId);
        $this->authorize('view', $project);
        $app = $project->patentApplication;

        $deadlines = DocketDeadline::with(['event', 'reviewer:id,name', 'ruleDefinition.approver:id,name'])
            ->where(function ($q) use ($project, $app) {
                $q->where('project_id', $project->id);
                if ($app) {
                    $q->orWhere('patent_application_id', $app->id);
                }
            })
            ->orderByRaw("CASE WHEN status = 'Open' THEN 0 ELSE 1 END")
            ->orderBy('due_date')
            ->get();

        $events = \App\Models\DocketEvent::with('creator:id,name')
            ->where(function ($q) use ($project, $app) {
                $q->where('project_id', $project->id);
                if ($app) {
                    $q->orWhere('patent_application_id', $app->id);
                }
            })
            ->orderByDesc('event_date')
            ->get();

        return response()->json([
            'application' => $app,
            'events'      => $events,
            'deadlines'   => $deadlines,
            'renewals'    => $app?->renewals ?? [],
            'event_types' => DocketRules::EVENT_TYPES,
            'rule_engine' => [
                'jurisdiction' => strtoupper((string) ($project->patent_office_code ?: $app?->jurisdiction ?: 'IN')),
                'approved_rules' => DeadlineRuleDefinition::where('jurisdiction', strtoupper((string) ($project->patent_office_code ?: $app?->jurisdiction ?: 'IN')))->where('status', 'Approved')->count(),
                'draft_rules' => DeadlineRuleDefinition::where('jurisdiction', strtoupper((string) ($project->patent_office_code ?: $app?->jurisdiction ?: 'IN')))->where('status', 'Draft')->count(),
                'reviewer' => $project->docketReviewer()->first(['id', 'name']),
            ],
            'capabilities' => [
                'can_manage' => ! $request->user()->isClientRole()
                    && $request->user()->can('update', $project),
                'can_approve_rules' => Gate::allows('approve-deadline-rules'),
                'can_review_deadlines' => Gate::allows('review-docket-deadline', $project),
            ],
        ]);
    }

    /**
     * Record a docket event; statutory deadlines are generated automatically.
     */
    public function storeEvent(Request $request, $projectId)
    {
        $validated = $request->validate([
            'event_type' => 'required|string|in:' . implode(',', array_keys(DocketRules::EVENT_TYPES)),
            'event_date' => 'required|date',
            'notes'      => 'nullable|string|max:2000',
        ]);

        $project = Project::findOrFail($projectId);
        $this->authorize('update', $project);

        $eventDate = Carbon::parse($validated['event_date']);
        $event = DB::transaction(function () use ($validated, $eventDate, $project, $request) {
            $event = DocketRules::recordEvent(
                $validated['event_type'], $eventDate, $project->id, $project->patent_application_id,
                $validated['notes'] ?? null, $request->user()->id
            );
            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'record_docket_event',
                'subject_type' => 'DocketEvent', 'subject_id' => $event->id,
                'metadata' => ['project_id' => $project->id, 'event_type' => $event->event_type, 'event_date' => $eventDate->toDateString(), 'deadlines_created' => $event->deadlines()->count()],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
            return $event;
        });

        return response()->json($event->load('deadlines'), 201);
    }

    public function updateEvent(Request $request, $eventId)
    {
        $validated = $request->validate([
            'event_type' => 'required|string|in:'.implode(',', array_keys(DocketRules::EVENT_TYPES)),
            'event_date' => 'required|date',
            'notes' => 'nullable|string|max:2000',
        ]);
        $event = \App\Models\DocketEvent::with(['project', 'application'])->findOrFail($eventId);
        $project = $event->project ?? $event->application?->projects()->first();
        abort_unless($project, 404);
        $this->authorize('update', $project);

        DB::transaction(function () use ($request, $event, $project, $validated): void {
            $event->deadlines()->delete();
            $event->update($validated);
            DocketRules::generateDeadlines($event, $project, $event->application);
            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'update_docket_event',
                'subject_type' => 'DocketEvent', 'subject_id' => $event->id,
                'metadata' => $validated + ['deadlines_regenerated' => $event->deadlines()->count()],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
        });

        return response()->json($event->fresh()->load('deadlines'));
    }

    public function destroyEvent(Request $request, $eventId)
    {
        $event = \App\Models\DocketEvent::with(['project', 'application.projects'])->findOrFail($eventId);
        $project = $event->project ?? $event->application?->projects->first();
        abort_unless($project, 404);
        $this->authorize('update', $project);
        DB::transaction(function () use ($request, $event): void {
            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'delete_docket_event',
                'subject_type' => 'DocketEvent', 'subject_id' => $event->id,
                'metadata' => ['event_type' => $event->event_type, 'event_date' => $event->event_date?->toDateString()],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
            $event->delete();
        });
        return response()->json(['message' => 'Docket event deleted.']);
    }

    public function storeDeadline(Request $request, $projectId)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255', 'due_date' => 'required|date',
            'extended_due_date' => 'nullable|date|after_or_equal:due_date',
            'legal_basis' => 'nullable|string|max:500', 'notes' => 'nullable|string|max:2000',
            'risk_level' => 'required|string|in:Low,Medium,High,Critical',
        ]);
        $project = Project::with('patentApplication')->findOrFail($projectId);
        $this->authorize('update', $project);
        $deadline = DB::transaction(function () use ($request, $project, $validated): DocketDeadline {
            $deadline = DocketDeadline::create($validated + [
                'project_id' => $project->id, 'ip_record_id' => $project->ip_record_id,
                'patent_application_id' => $project->patentApplication?->id,
                'statutory_due_date' => $validated['due_date'], 'source_type' => 'Manual',
                'review_status' => 'Approved', 'status' => 'Open',
            ]);
            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'create_docket_deadline',
                'subject_type' => 'DocketDeadline', 'subject_id' => $deadline->id,
                'metadata' => ['project_id' => $project->id, 'due_date' => $deadline->due_date->toDateString()],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
            return $deadline;
        });
        return response()->json($deadline, 201);
    }

    /**
     * Complete / waive / reopen a deadline.
     */
    public function updateDeadline(Request $request, $deadlineId)
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'due_date' => 'sometimes|required|date',
            'extended_due_date' => 'nullable|date',
            'legal_basis' => 'nullable|string|max:500',
            'risk_level' => 'sometimes|required|string|in:Low,Medium,High,Critical',
            'status' => 'sometimes|required|string|in:Open,Completed,Missed,Waived',
            'notes'  => 'nullable|string|max:2000',
        ]);

        $deadline = DocketDeadline::with(['project', 'application.projects'])->findOrFail($deadlineId);
        $project = $deadline->project ?? $deadline->application?->projects->first();
        abort_unless($project, 404);
        $this->authorize('update', $project);
        DB::transaction(function () use ($request, $deadline, $validated): void {
            $changes = $validated;
            if (isset($validated['due_date'])) $changes['statutory_due_date'] = $validated['due_date'];
            if (isset($validated['status'])) $changes['completed_at'] = $validated['status'] === 'Completed' ? now() : null;
            $deadline->update($changes);
            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'deadline_status_change', 'subject_type' => 'DocketDeadline',
                'subject_id' => $deadline->id, 'metadata' => ['status' => $deadline->status, 'notes' => $validated['notes'] ?? null],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
        });

        return response()->json($deadline);
    }

    public function destroyDeadline(Request $request, $deadlineId)
    {
        $deadline = DocketDeadline::with(['project', 'application.projects'])->findOrFail($deadlineId);
        $project = $deadline->project ?? $deadline->application?->projects->first();
        abort_unless($project, 404);
        $this->authorize('update', $project);
        DB::transaction(function () use ($request, $deadline): void {
            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'delete_docket_deadline',
                'subject_type' => 'DocketDeadline', 'subject_id' => $deadline->id,
                'metadata' => ['title' => $deadline->title, 'due_date' => $deadline->due_date?->toDateString()],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
            $deadline->delete();
        });
        return response()->json(['message' => 'Deadline deleted.']);
    }

    public function rules(Request $request)
    {
        Gate::authorize('approve-deadline-rules');
        return response()->json(DeadlineRuleDefinition::with('approver:id,name')
            ->orderBy('jurisdiction')->orderBy('event_type')->orderBy('rule_code')->get());
    }

    public function approveRule(Request $request, $ruleId)
    {
        Gate::authorize('approve-deadline-rules');
        $validated = $request->validate(['status' => 'required|string|in:Approved,Retired']);

        return DB::transaction(function () use ($request, $validated, $ruleId) {
            $rule = DeadlineRuleDefinition::lockForUpdate()->findOrFail($ruleId);
            abort_if($validated['status'] === 'Approved' && (int) $rule->created_by === (int) $request->user()->id, 422, 'The rule creator cannot approve the same rule version.');
            $rule->update([
                'status' => $validated['status'],
                'approved_by' => $validated['status'] === 'Approved' ? $request->user()->id : null,
                'approved_at' => $validated['status'] === 'Approved' ? now() : null,
            ]);
            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'deadline_rule_status_change',
                'subject_type' => 'DeadlineRuleDefinition', 'subject_id' => $rule->id,
                'metadata' => ['rule_code' => $rule->rule_code, 'version' => $rule->version, 'status' => $rule->status],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
            return response()->json($rule->fresh('approver:id,name'));
        });
    }

    public function copyRule(Request $request, $ruleId)
    {
        Gate::authorize('approve-deadline-rules');
        $validated = $request->validate(['version' => ['required', 'string', 'max:32'], 'effective_from' => ['required', 'date']]);
        return DB::transaction(function () use ($request, $validated, $ruleId) {
            $source = DeadlineRuleDefinition::lockForUpdate()->findOrFail($ruleId);
            $copy = $source->replicate(['status', 'approved_by', 'approved_at']);
            $copy->fill(['version' => $validated['version'], 'effective_from' => $validated['effective_from'],
                'status' => 'Draft', 'source_type' => 'Firm', 'parent_rule_id' => $source->id, 'created_by' => $request->user()->id]);
            $copy->save();
            AuditLog::create(['user_id' => $request->user()->id, 'action' => 'copy_deadline_rule', 'subject_type' => 'DeadlineRuleDefinition',
                'subject_id' => $copy->id, 'metadata' => ['source_rule_id' => $source->id, 'version' => $copy->version],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
            return response()->json($copy, 201);
        });
    }

    public function simulateRule(Request $request, $ruleId)
    {
        Gate::authorize('approve-deadline-rules');
        $validated = $request->validate(['anchor_date' => ['required', 'date']]);
        $rule = DeadlineRuleDefinition::findOrFail($ruleId);
        return response()->json(app(\App\Services\DeadlineRuleEngine::class)->simulateRule($rule, Carbon::parse($validated['anchor_date'])));
    }

    /**
     * Fields the IPO-style status view shows but nothing else in the docketing
     * engine auto-generates (no DocketRules event produces them) — staff enter
     * these by hand. Everything else on that view (filing/publication/RFE/grant
     * dates, application number) already comes from existing PatentApplication
     * fields kept in sync by DocketRules::recordEvent().
     */
    public function updateApplication(Request $request, $projectId)
    {
        $project = Project::with('patentApplication')->findOrFail($projectId);
        $this->authorize('update', $project);
        abort_unless($project->patentApplication, 422, 'This case has no linked patent application yet.');

        $validated = $request->validate([
            'application_type'         => 'nullable|string|max:100',
            'fer_reply_date'           => 'nullable|date',
            'certificate_issue_date'   => 'nullable|date',
            'post_grant_journal_date'  => 'nullable|date',
        ]);

        $project->patentApplication->update($validated);

        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => 'update_application_status_fields',
            'subject_type' => 'PatentApplication', 'subject_id' => $project->patentApplication->id,
            'metadata' => $validated,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($project->patentApplication->fresh());
    }

    /**
     * Manually add a renewal-year row for a project's application.
     *
     * `DocketRules::generateRenewalSchedule()` auto-generates the full 3-20 year
     * schedule off a `granted` docket event, but that rule is India-S.53-specific.
     * For non-IN applications (or IN cases outside that auto-generation path)
     * staff add renewal-year rows here by hand — same table, same status
     * lifecycle (Unpaid/Paid/Waived/Lapsed), just entered manually instead of
     * computed from a statutory formula.
     */
    public function storeRenewal(Request $request, $projectId)
    {
        $validated = $request->validate([
            'renewal_year' => 'required|integer|min:1|max:30',
            'due_date'     => 'required|date',
        ]);

        $project = Project::with('patentApplication')->findOrFail($projectId);
        $this->authorize('update', $project);
        abort_unless($project->patentApplication, 422, 'This case has no linked patent application yet — set an application number first.');

        $renewal = \App\Models\RenewalSchedule::updateOrCreate(
            ['patent_application_id' => $project->patentApplication->id, 'renewal_year' => $validated['renewal_year']],
            ['due_date' => $validated['due_date'], 'status' => 'Unpaid']
        );

        return response()->json($renewal, 201);
    }

    /**
     * Mark a renewal year paid / waived.
     */
    public function updateRenewal(Request $request, $renewalId)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:Unpaid,Paid,Waived,Lapsed',
        ]);

        $renewal = \App\Models\RenewalSchedule::with('application.projects')->findOrFail($renewalId);
        $project = $renewal->application?->projects->first();
        abort_unless($project, 404);
        $this->authorize('update', $project);
        $renewal->update([
            'status'  => $validated['status'],
            'paid_at' => $validated['status'] === 'Paid' ? now() : null,
        ]);

        return response()->json($renewal);
    }

    /**
     * Firm-wide upcoming deadlines (docketing dashboard feed).
     */
    public function upcoming(Request $request)
    {
        $days = min((int) $request->input('days', 90), 365);

        $deadlines = DocketDeadline::with(['project:id,docket_number,project_name,client_id', 'project.client:id,company_name,legal_name'])
            ->where('status', 'Open')
            ->whereDate('due_date', '<=', now()->addDays($days))
            ->orderBy('due_date')
            ->limit(500)
            ->get()
            ->filter(fn (DocketDeadline $deadline) => $deadline->project
                && $request->user()->can('view', $deadline->project))
            ->take(200)
            ->values();

        return response()->json($deadlines);
    }
}
