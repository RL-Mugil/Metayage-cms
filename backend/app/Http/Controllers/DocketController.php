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

        $event = DocketRules::recordEvent(
            $validated['event_type'],
            Carbon::parse($validated['event_date']),
            $project->id,
            $project->patent_application_id,
            $validated['notes'] ?? null,
            $request->user()->id
        );

        return response()->json($event->load('deadlines'), 201);
    }

    /**
     * Complete / waive / reopen a deadline.
     */
    public function updateDeadline(Request $request, $deadlineId)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:Open,Completed,Missed,Waived',
            'notes'  => 'nullable|string|max:2000',
        ]);

        $deadline = DocketDeadline::with(['project', 'application.projects'])->findOrFail($deadlineId);
        $project = $deadline->project ?? $deadline->application?->projects->first();
        abort_unless($project, 404);
        $this->authorize('update', $project);
        $deadline->update([
            'status'       => $validated['status'],
            'completed_at' => $validated['status'] === 'Completed' ? now() : null,
            'notes'        => $validated['notes'] ?? $deadline->notes,
        ]);

        return response()->json($deadline);
    }

    public function reviewDeadline(Request $request, $deadlineId)
    {
        $validated = $request->validate([
            'review_status' => 'required|string|in:Approved,Rejected',
            'notes' => 'nullable|string|max:2000',
        ]);
        $deadline = DocketDeadline::with(['project', 'application.projects'])->findOrFail($deadlineId);
        $project = $deadline->project ?? $deadline->application?->projects->first();
        abort_unless($project, 404);
        Gate::authorize('review-docket-deadline', $project);

        return DB::transaction(function () use ($deadline, $validated, $request) {
            $deadline->update([
                'review_status' => $validated['review_status'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'notes' => $validated['notes'] ?? $deadline->notes,
            ]);
            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'review_deadline',
                'subject_type' => 'DocketDeadline', 'subject_id' => $deadline->id,
                'metadata' => ['review_status' => $validated['review_status'], 'rule_code' => $deadline->rule_code, 'rule_version' => $deadline->rule_version],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
            return response()->json($deadline->fresh('reviewer:id,name'));
        });
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
