<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use App\Http\PaginationHelper;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Illuminate\Validation\ValidationException;
use App\Services\GoogleCalendarService;
use App\Http\Controllers\ProjectTrackerController;

class ProjectController extends Controller
{
    public function inertiaIndex(Request $request)
    {
        return Inertia::render('Projects');
    }

    public function inertiaShow(Request $request, $id)
    {
        return Inertia::render('ProjectShow', ['projectId' => (int) $id]);
    }

    public function stats(Request $request)
    {
        $user = $request->user();
        $base = Project::query();

        if ($user->isClientRole()) {
            $base->whereHas('client', function ($q) use ($user) {
                $q->visibleToUser($user);
            });
        } elseif ($user->isGalvanizer()) {
            $base->where($this->galvanizerWhereClause($user, $request->input('role_filter')));
        } elseif (in_array($user->role, ['partner', 'director'], true)) {
            $rf = $request->input('role_filter');
            if ($rf && $rf !== 'all') {
                $base->where($this->analystWhereClause($user, $rf));
            }
            // else: no restriction — partner/director sees all projects
        } elseif ($user->role === 'associate') {
            $base->where($this->analystWhereClause($user, $request->input('role_filter')));
        }

        $today = now()->toDateString();
        $rf = $request->input('role_filter', 'all');

        $cacheKey = "project_stats_{$user->id}_{$user->role}_{$rf}_v" . Cache::get('dashboard_v', 0);
        $stats = Cache::remember($cacheKey, 300, function () use ($base, $today) {
            $row = (clone $base)->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'Open' THEN 1 ELSE 0 END) as open,
                SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'On Hold' THEN 1 ELSE 0 END) as on_hold,
                SUM(CASE WHEN hard_deadline IS NOT NULL AND hard_deadline < ? AND status NOT IN ('Closed', 'Completed') THEN 1 ELSE 0 END) as overdue
            ", [$today])->first();
            return [
                'total' => (int) ($row?->total ?? 0),
                'open' => (int) ($row?->open ?? 0),
                'in_progress' => (int) ($row?->in_progress ?? 0),
                'on_hold' => (int) ($row?->on_hold ?? 0),
                'overdue' => (int) ($row?->overdue ?? 0),
            ];
        });

        return response()->json($stats);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Project::with('client', 'partner', 'manager', 'patentEngineer', 'stages');

        // RBAC access filter
        if ($user->isClientRole()) {
            $query->whereHas('client', function ($q) use ($user) {
                $q->visibleToUser($user);
            });
        } elseif ($user->isGalvanizer()) {
            $query->where($this->galvanizerWhereClause($user, $request->input('role_filter')));
        } elseif (in_array($user->role, ['partner', 'director'], true)) {
            $rf = $request->input('role_filter');
            if ($rf && $rf !== 'all') {
                $query->where($this->analystWhereClause($user, $rf));
            }
        } elseif ($user->role === 'associate') {
            $query->where($this->analystWhereClause($user, $request->input('role_filter')));
        }

        if ($request->filled('search')) {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->search);
            $query->where(function ($q) use ($search) {
                $sl = strtolower($search);
                $q->whereRaw('LOWER(project_name) LIKE ?', ["%{$sl}%"])
                    ->orWhereRaw('LOWER(project_code) LIKE ?', ["%{$sl}%"])
                    ->orWhereRaw('LOWER(docket_number) LIKE ?', ["%{$sl}%"])
                    ->orWhereRaw('LOWER(invention_title) LIKE ?', ["%{$sl}%"]);
            });
        }

        if ($request->filled('status') && $request->status !== 'All') {
            $statuses = array_map('trim', explode(',', $request->status));
            count($statuses) > 1 ? $query->whereIn('status', $statuses) : $query->where('status', $statuses[0]);
        }

        if ($request->filled('exclude_status')) {
            $excluded = array_map('trim', explode(',', $request->exclude_status));
            $query->whereNotIn('status', $excluded);
        }

        if ($request->boolean('overdue')) {
            $today = now()->toDateString();
            $query->whereNotNull('hard_deadline')
                ->where('hard_deadline', '<', $today)
                ->whereNotIn('status', ['Closed', 'Completed']);
        }

        if ($request->filled('patent_engineer_id')) {
            $query->where('patent_engineer_id', (int) $request->patent_engineer_id);
        }

        if ($request->filled('assigned_manager_id')) {
            $query->where('assigned_manager_id', (int) $request->assigned_manager_id);
        }

        if ($request->filled('project_type')) {
            $types = array_map('trim', explode(',', $request->project_type));
            count($types) > 1 ? $query->whereIn('project_type', $types) : $query->where('project_type', $types[0]);
        }

        // Filter by current lifecycle stage (the stage currently In Progress)
        if ($request->filled('lifecycle_stage')) {
            $stage = $request->lifecycle_stage;
            $query->whereHas('stages', fn ($q) => $q->where('stage_name', $stage)->where('status', 'In Progress'));
        }

        $sortBy = in_array($request->sort_by, ['project_name', 'docket_number', 'status', 'hard_deadline', 'filing_date'])
            ? $request->sort_by : 'hard_deadline';
        $sortDir = $request->sort_dir === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortBy, $sortDir);

        return response()->json(PaginationHelper::paginate($query, $request));
    }

    /** Count of projects currently sitting in each lifecycle stage. */
    public function lifecycleStats(Request $request)
    {
        $user = $request->user();

        $q = DB::table('project_stages as ps')
            ->join('projects as p', 'ps.project_id', '=', 'p.id')
            ->whereNull('p.deleted_at')
            ->where('ps.status', 'In Progress');

        if ($user->isClientRole()) {
            $client = $request->attributes->get('portal_client') ?? Client::forUser($user);
            if (! $client) return response()->json([]);
            $q->where('p.client_id', $client->id);
        } elseif ($user->isGalvanizer()) {
            $rf = $request->input('role_filter');
            $q->where($this->galvanizerWhereClauseRaw($user, $rf));
        } elseif (in_array($user->role, ['partner', 'director'], true)) {
            $rf  = $request->input('role_filter');
            $uid = $user->id;
            if ($rf && $rf !== 'all') {
                $q->where(function ($w) use ($uid, $rf) {
                    match ($rf) {
                        'pcm' => $w->where('p.assigned_manager_id', $uid),
                        'scm' => $w->where('p.secondary_manager_id', $uid),
                        'pr'  => $w->where('p.patent_engineer_id', $uid),
                        default => null,
                    };
                });
            }
        }

        $counts = $q->selectRaw('ps.stage_name, COUNT(*) as count')
            ->groupBy('ps.stage_name')
            ->pluck('count', 'stage_name');

        return response()->json($counts);
    }

    public function show(Request $request, $id)
    {
        $project = Project::with('client', 'partner', 'manager', 'stages.owner', 'tasks.assignee')->findOrFail($id);

        $this->authorize('view', $project);

        return response()->json($project);
    }

    public function store(StoreProjectRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        $validated['assigned_partner_id'] = $validated['assigned_partner_id'] ?? $user->id;
        $validated['assigned_manager_id'] = $validated['assigned_manager_id'] ?? $user->id;

        if ($user->isGalvanizer()) {
            $clientCircle = Client::where('id', $validated['client_id'])->value('circle');
            if ($clientCircle && ! $user->canAccessCircle($clientCircle)) {
                return response()->json(['message' => 'Selected client is outside your assigned circle.'], 403);
            }
            $validated['circle'] = $validated['circle'] ?? $clientCircle ?? $user->defaultGalvanizerCircleCode();
            if (! $user->canAccessCircle($validated['circle'] ?? null)) {
                return response()->json(['message' => 'Select one of your assigned circles.'], 422);
            }
        }

        $project = \DB::transaction(fn () => $this->createProjectWithCodes($validated));

        // Audit Log
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'create',
            'subject_type' => 'Project',
            'subject_id' => $project->id,
            'metadata' => ['project_name' => $project->project_name, 'project_code' => $project->project_code],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        if ($project->hard_deadline) {
            rescue(fn () => app(GoogleCalendarService::class)->syncProjectDeadline($project->fresh()));
        }

        Cache::increment('dashboard_v');
        return response()->json($project, 201);
    }

    /** Entry point for bulk import (must already be inside a transaction). */
    public function createFromImport(array $validated): Project
    {
        return $this->createProjectWithCodes($validated);
    }

    /**
     * Create a project with generated project_code + docket_number and
     * seeded lifecycle stages. Must be called inside a DB transaction.
     */
    private function createProjectWithCodes(array $validated): Project
    {
        $recordMode = $validated['record_mode'] ?? 'new';
        $manualProjectCode = strtoupper(trim((string) ($validated['project_code'] ?? '')));
        $manualDocket = strtoupper(trim((string) ($validated['docket_number'] ?? '')));

        if ($recordMode === 'existing') {
            if ($manualProjectCode !== '' && $manualDocket !== '' && $manualProjectCode !== $manualDocket) {
                throw ValidationException::withMessages([
                    'project_code' => 'Project code and UIN must be identical.',
                    'docket_number' => 'Project code and UIN must be identical.',
                ]);
            }

            $canonicalId = $manualProjectCode !== '' ? $manualProjectCode : $manualDocket;
            if ($canonicalId === '') {
                throw ValidationException::withMessages([
                    'project_code' => 'Case ID is required.',
                ]);
            }

            $idExists = Project::withTrashed()
                ->lockForUpdate()
                ->where(function ($query) use ($canonicalId) {
                    $query->where('project_code', $canonicalId)
                        ->orWhere('docket_number', $canonicalId);
                })
                ->exists();

            if ($idExists) {
                throw ValidationException::withMessages([
                    'project_code' => 'Case ID already exists.',
                    'docket_number' => 'Case ID already exists.',
                ]);
            }

            $validated['project_code'] = $canonicalId;
            $validated['docket_number'] = $canonicalId;
        } else {
            if ($manualProjectCode !== '' && $manualDocket !== '' && $manualProjectCode !== $manualDocket) {
                throw ValidationException::withMessages([
                    'project_code' => 'Project code and UIN must be identical.',
                    'docket_number' => 'Project code and UIN must be identical.',
                ]);
            }

            $manualCanonicalId = $manualProjectCode !== '' ? $manualProjectCode : $manualDocket;

            if ($manualCanonicalId !== '') {
                $idExists = Project::withTrashed()
                    ->lockForUpdate()
                    ->where(function ($query) use ($manualCanonicalId) {
                        $query->where('project_code', $manualCanonicalId)
                            ->orWhere('docket_number', $manualCanonicalId);
                    })
                    ->exists();

                if ($idExists) {
                    throw ValidationException::withMessages([
                        'project_code' => 'Case ID already exists.',
                        'docket_number' => 'Case ID already exists.',
                    ]);
                }

                $validated['project_code'] = $manualCanonicalId;
                $validated['docket_number'] = $manualCanonicalId;
            } else {
                $client = Client::findOrFail($validated['client_id']);
                $clientCode = $client->client_code ?? '';
                $maxSeq = Project::where('client_id', $client->id)
                    ->whereNotNull('docket_number')
                    ->lockForUpdate()
                    ->get()
                    ->map(fn($p) => strlen($p->docket_number) >= strlen($clientCode) + 3
                        ? (int) substr($p->docket_number, strlen($clientCode), 3) : 0)
                    ->max() ?? 0;
                $docketSeq = str_pad($maxSeq + 1, 3, '0', STR_PAD_LEFT);
                $office = strtoupper($validated['patent_office_code'] ?? '');
                $service = strtoupper($validated['service_code'] ?? '');
                $canonicalId = $clientCode . $docketSeq . $office . $service;
                $validated['project_code'] = $canonicalId;
                $validated['docket_number'] = $canonicalId;
            }
        }

        $validated['status'] = $validated['status'] ?? 'Open';
        unset($validated['record_mode']);

        // Auto-inherit circle from client if not explicitly provided
        if (empty($validated['circle']) && !empty($validated['client_id'])) {
            $clientCircle = \App\Models\Client::where('id', $validated['client_id'])->value('circle');
            if ($clientCircle) {
                $validated['circle'] = $clientCircle;
            }
        }

        $project = Project::create($validated);

        // Seed pipeline stages based on service code
        $svc = strtoupper($validated['service_code'] ?? '');
        $serviceStages = match (true) {
            $svc === 'PAS' || $svc === 'SRH' || $svc === 'PAT' || $svc === 'FTO' => [
                "Prior Art Search",
                "Search Report Ready",
                "Search Report Shared",
                "Awaiting IDF from Client",
            ],
            $svc === 'PRV' => [
                "IDF Received",
                "Drafting in Progress",
                "Internal Review",
                "Awaiting Signed Forms",
                "Filing",
                "Filed",
            ],
            $svc === 'CPT' || $svc === 'NPA' => [
                "IDF Received",
                "Claims Ready to Share",
                "Claims Approved",
                "Drafting in Progress",
                "Internal Review",
                "Draft Shared with Client",
                "Awaiting Client Feedback",
                "Client Comments Received",
                "Revised Draft Shared",
                "Draft Approved",
                "Awaiting Signed Forms",
                "Filing",
                "Filed",
            ],
            $svc === 'FER' || $svc === 'SER' || $svc === 'TER' => [
                "FER Received",
                "FER Response in Progress",
                "FER Response Filed",
            ],
            $svc === 'HRG' => [
                "Hearing Scheduled",
                "Hearing Response in Progress",
                "Hearing Response Filed",
                "Granted",
            ],
            default => [
                "Invention Disclosure",
                "Patent Search",
                "Search Report",
                "Provisional or Complete Application",
                "Provisional Filing",
                "Patent Drafting",
                "Applicant/Inventor Review",
                "Filing with Patent Office",
                "First Examination Report",
                "FER Response Preparation",
                "FER Response Filing",
                "Hearing with Examiner",
                "Hearing Response Preparation",
                "Hearing Response Filing",
            ],
        };
        foreach ($serviceStages as $index => $stageName) {
            ProjectStage::create([
                'project_id' => $project->id,
                'stage_name' => $stageName,
                'status' => $index === 0 ? 'In Progress' : 'Pending',
                'sequence_order' => $index,
                'duration_days' => 15,
                'due_date' => Carbon::now()->addDays(($index + 1) * 15),
            ]);
        }

        return $project;
    }

    public function update(UpdateProjectRequest $request, $id)
    {
        $user = $request->user();
        $project = Project::findOrFail($id);
        $this->authorize('update', $project);
        $validated = $request->validated();

        if ($user->isGalvanizer() && array_key_exists('circle', $validated) && ! $user->canAccessCircle($validated['circle'])) {
            return response()->json(['message' => 'You cannot move a case outside your assigned circle.'], 403);
        }

        $deadlineChanged = array_key_exists('hard_deadline', $validated)
            && $validated['hard_deadline'] !== $project->hard_deadline;

        $project->update($validated);

        // When filing_date is set, auto-advance the "Filing" stage to "Filed"
        if (array_key_exists('filing_date', $validated) && !empty($validated['filing_date'])) {
            $filedStage = $project->stages()->where('stage_name', 'Filed')->first();
            if ($filedStage && $filedStage->status !== 'In Progress' && $filedStage->status !== 'Completed') {
                $now = Carbon::now();
                $project->stages()
                    ->where('sequence_order', '<', $filedStage->sequence_order)
                    ->update(['status' => 'Completed', 'actual_end_at' => $now]);
                $filedStage->update(['status' => 'In Progress', 'actual_start_at' => $now]);
                $project->stages()
                    ->where('sequence_order', '>', $filedStage->sequence_order)
                    ->update(['status' => 'Pending', 'actual_start_at' => null, 'actual_end_at' => null]);
                ProjectTrackerController::syncTrackerRowStatus($project->id, 'Filed');
            }
        }

        // Audit Log
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'update',
            'subject_type' => 'Project',
            'subject_id' => $project->id,
            'metadata' => $validated,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        if ($deadlineChanged) {
            $fresh = $project->fresh();
            rescue(fn () => $fresh->hard_deadline
                ? app(GoogleCalendarService::class)->syncProjectDeadline($fresh)
                : app(GoogleCalendarService::class)->removeProjectDeadline($fresh)
            );
        }

        Cache::increment('dashboard_v');
        return response()->json($project);
    }

    public function updateStage(Request $request, $id)
    {
        $user = $request->user();
        $project = Project::findOrFail($id);

        $this->authorize('update', $project);

        // Status-only update (no pipeline stage change): update the project's top-level status field.
        if ($request->filled('status') && !$request->filled('stage_name')) {
            $request->validate(['status' => 'required|string|in:Draft,Open,Active,In Progress,On Hold,Closed,Completed']);
            $project->update(['status' => $request->status]);

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'status_change',
                'subject_type' => 'Project',
                'subject_id' => $project->id,
                'metadata' => ['new_status' => $request->status],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'message' => "Project status updated to {$request->status}",
                'project' => $project->fresh(),
            ]);
        }

        $request->validate([
            'stage_name' => 'required|string',
            'status' => 'nullable|string', // Pending, In Progress, Completed
        ]);

        $stageName = $request->stage_name;
        $targetStage = ProjectStage::where('project_id', $project->id)->where('stage_name', $stageName)->firstOrFail();

        // 1. Mark previous active stages as Completed
        ProjectStage::where('project_id', $project->id)
            ->where('sequence_order', '<', $targetStage->sequence_order)
            ->update([
                'status' => 'Completed',
                'actual_end_at' => Carbon::now()
            ]);

        // 2. Mark current target stage as In Progress
        $targetStage->update([
            'status' => 'In Progress',
            'actual_start_at' => Carbon::now()
        ]);

        // 3. Mark upcoming stages as Pending
        ProjectStage::where('project_id', $project->id)
            ->where('sequence_order', '>', $targetStage->sequence_order)
            ->update([
                'status' => 'Pending',
                'actual_start_at' => null,
                'actual_end_at' => null
            ]);

        // Reverse sync: update linked tracker row status to match the new stage.
        ProjectTrackerController::syncTrackerRowStatus($project->id, $stageName);

        // Log audit event
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'stage_change',
            'subject_type' => 'Project',
            'subject_id' => $project->id,
            'metadata' => ['new_stage' => $stageName],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // Notify assigned manager via ip_notifications (the table the UI reads from)
        if ($project->assigned_manager_id) {
            \App\Support\Notifier::push(
                $project->assigned_manager_id,
                'system',
                'Case Stage Updated',
                "Case '{$project->project_name}' has been moved to '{$stageName}' stage.",
                "/projects/{$project->id}",
                ['project_id' => $project->id, 'stage' => $stageName],
            );
        }

        Cache::increment('dashboard_v');
        return response()->json([
            'message' => "Project stage updated to {$stageName}",
            'project' => Project::with('stages')->find($project->id)
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $project = Project::findOrFail($id);
        $this->authorize('delete', $project);

        if (in_array($project->status, ['Active', 'In Progress', 'Open'])) {
            return response()->json(['message' => 'Cannot delete an active project. Close or archive it first.'], 403);
        }

        $project->delete();
        Cache::increment('dashboard_v');
        return response()->json(['message' => 'Case deleted']);
    }

    /**
     * Build the WHERE closure that scopes a Patent Analyst's query.
     * role_filter: pcm → only assigned_manager_id, scm → secondary_manager_id,
     * pr → patent_engineer_id, anything else → all three + task-assigned.
     */
    private function analystWhereClause($user, ?string $roleFilter): \Closure
    {
        return function ($q) use ($user, $roleFilter) {
            match ($roleFilter) {
                'pcm' => $q->where('assigned_manager_id', $user->id),
                'scm' => $q->where('secondary_manager_id', $user->id),
                'pr'  => $q->where('patent_engineer_id', $user->id),
                default => $q->where('patent_engineer_id', $user->id)
                              ->orWhere('assigned_manager_id', $user->id)
                              ->orWhere('secondary_manager_id', $user->id)
                              ->orWhereHas('tasks', fn ($t) => $t->where('assignee_id', $user->id)),
            };
        };
    }

    /**
     * Galvanizer: when role_filter is pcm/scm/pr, narrow to that assignment column only.
     * When filter is "all" (or absent), apply full circle + assigned scope.
     * For Eloquent queries (projects table, no alias).
     */
    private function galvanizerWhereClause($user, ?string $roleFilter): \Closure
    {
        return function ($q) use ($user, $roleFilter) {
            match ($roleFilter) {
                'pcm' => $q->where('assigned_manager_id', $user->id),
                'scm' => $q->where('secondary_manager_id', $user->id),
                'pr'  => $q->where('patent_engineer_id', $user->id),
                default => $user->applyProjectScope($q),
            };
        };
    }

    /**
     * Full project detail: stages (with dates + working-day durations), tasks,
     * invoices, and per-project ledger entries. Used by the project detail panel.
     */
    public function detail(Request $request, $id)
    {
        $user = $request->user();
        $project = Project::with([
            'stages' => fn ($q) => $q->orderBy('sequence_order'),
            'client:id,company_name,legal_name,client_code',
        ])->findOrFail($id);
        $this->authorize('view', $project);

        // Tasks for this project
        $tasks = \App\Models\Task::where('project_id', $project->id)
            ->with('assignee:id,name')
            ->orderBy('created_at')
            ->get(['id', 'title', 'status', 'priority', 'due_date', 'assignee_id',
                   'estimated_hours', 'actual_hours', 'billable', 'created_at', 'updated_at']);

        // Invoices for this project
        $invoices = \App\Models\Invoice::where('project_id', $project->id)
            ->orderBy('created_at')
            ->get(['id', 'invoice_code', 'status', 'total_amount', 'balance_due',
                   'subtotal', 'tax_amount', 'currency', 'created_at', 'due_date']);

        // Ledger entries for this project's invoices
        $invoiceIds = $invoices->pluck('id');
        $ledger = \App\Models\ClientLedger::whereIn('document_reference', $invoices->pluck('invoice_code'))
            ->orderBy('created_at')
            ->get(['id', 'document_type', 'document_reference', 'debit', 'credit', 'balance', 'created_at']);

        // Working-day duration helper (excludes Sat & Sun)
        $workingDays = function (?string $start, ?string $end): ?int {
            if (!$start || !$end) return null;
            $s = Carbon::parse($start)->startOfDay();
            $e = Carbon::parse($end)->startOfDay();
            if ($e->lt($s)) return 0;
            $days = 0;
            $cur = $s->copy();
            while ($cur->lte($e)) {
                if (!$cur->isWeekend()) $days++;
                $cur->addDay();
            }
            return $days;
        };

        // Enrich stages with working-day durations and total
        $stages = $project->stages->map(function ($stage) use ($workingDays) {
            $dur = $workingDays(
                $stage->actual_start_at?->toDateTimeString(),
                $stage->actual_end_at?->toDateTimeString()
            );
            return [
                'id'             => $stage->id,
                'stage_name'     => $stage->stage_name,
                'status'         => $stage->status,
                'sequence_order' => $stage->sequence_order,
                'actual_start_at' => $stage->actual_start_at?->toDateTimeString(),
                'actual_end_at'   => $stage->actual_end_at?->toDateTimeString(),
                'due_date'       => $stage->due_date,
                'working_days'   => $dur,
            ];
        });

        $totalStageDays = $stages->sum(fn ($s) => $s['working_days'] ?? 0);

        // Enrich tasks with working-day durations
        // Use created_at as start proxy; completed_at = updated_at when status is Completed
        $enrichedTasks = $tasks->map(function ($task) use ($workingDays) {
            $endDate = ($task->status === 'Completed') ? $task->updated_at : null;
            $dur = $workingDays($task->created_at, $endDate);
            $t = $task->toArray();
            $t['working_days'] = $dur;
            $t['completed_at'] = $endDate;
            return $t;
        });

        // Invoice summary
        $totalInvoiced  = $invoices->whereNotIn('status', ['Draft'])->sum('total_amount');
        $totalReceived  = $invoices->whereIn('status', ['Paid'])->sum('total_amount')
            + $invoices->where('status', 'Partially Paid')->sum(fn ($i) => $i->total_amount - $i->balance_due);
        $totalPending   = $invoices->whereIn('status', ['Sent', 'Overdue', 'Partially Paid', 'Viewed'])->sum('balance_due');

        return response()->json([
            'project'              => $project,
            'stages'               => $stages,
            'total_stage_days'     => $totalStageDays,
            'tasks'                => $enrichedTasks,
            'invoices'             => $invoices,
            'ledger'               => $ledger,
            'invoice_summary'      => [
                'total_invoiced' => $totalInvoiced,
                'total_received' => $totalReceived,
                'total_pending'  => $totalPending,
            ],
        ]);
    }

    /**
     * Galvanizer scope for raw DB queries that use the "p" table alias.
     */
    private function galvanizerWhereClauseRaw($user, ?string $roleFilter): \Closure
    {
        return function ($q) use ($user, $roleFilter) {
            $uid = $user->id;
            match ($roleFilter) {
                'pcm' => $q->where('p.assigned_manager_id', $uid),
                'scm' => $q->where('p.secondary_manager_id', $uid),
                'pr'  => $q->where('p.patent_engineer_id', $uid),
                default => (function () use ($q, $user, $uid) {
                    $codes = $user->galvanizerCircleCodes();
                    $q->where(function ($w) use ($codes, $uid) {
                        $w->whereIn('p.circle', $codes)
                          ->orWhere('p.assigned_manager_id', $uid)
                          ->orWhere('p.secondary_manager_id', $uid)
                          ->orWhere('p.patent_engineer_id', $uid);
                    });
                })(),
            };
        };
    }
}
