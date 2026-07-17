<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectElevation;
use App\Models\ProjectStage;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use App\Http\PaginationHelper;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\MatterWorkspaceResource;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Illuminate\Validation\ValidationException;
use App\Services\GoogleCalendarService;
use App\Services\MatterWorkspaceService;
use App\Services\DocketNumberService;
use App\Services\InventionFamilyService;
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
                SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) as closed,
                SUM(CASE WHEN patent_granted = true OR status = 'Granted' THEN 1 ELSE 0 END) as granted,
                SUM(CASE WHEN hard_deadline IS NOT NULL AND hard_deadline < ? AND status NOT IN ('Closed', 'Completed') THEN 1 ELSE 0 END) as overdue
            ", [$today])->first();
            return [
                'total'       => (int) ($row?->total ?? 0),
                'open'        => (int) ($row?->open ?? 0),
                'in_progress' => (int) ($row?->in_progress ?? 0),
                'on_hold'     => (int) ($row?->on_hold ?? 0),
                'completed'   => (int) ($row?->completed ?? 0),
                'closed'      => (int) ($row?->closed ?? 0),
                'granted'     => (int) ($row?->granted ?? 0),
                'overdue'     => (int) ($row?->overdue ?? 0),
            ];
        });

        return response()->json($stats);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        // Load only the minimal relations needed for the list view.
        // - client: needed for display and GST-type invoice routing.
        // - stages: limited to only the In Progress stage (not all 7 per project).
        //   This cuts response size by ~7× vs eager-loading the full stage list.
        // - partner/manager/patentEngineer are intentionally omitted — the frontend
        //   falls back to the separately-loaded users array via userName().
        $query = Project::with([
            'client:id,client_code,company_name,legal_name,nationality,gst_type',
            'stages' => fn ($q) => $q->where('status', 'In Progress')
                                     ->select('project_id', 'stage_name', 'status', 'sequence_order'),
        ]);

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

        if ($request->filled('patent_granted')) {
            $query->where('patent_granted', (bool) $request->patent_granted);
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

        if ($request->filled('service_code')) {
            $codes = array_map('strtoupper', array_map('trim', explode(',', $request->service_code)));
            count($codes) > 1 ? $query->whereIn('service_code', $codes) : $query->where('service_code', $codes[0]);
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

    /** Count of active projects per service code (for lifecycle diagram node badges). */
    public function lifecycleServiceStats(Request $request)
    {
        $user = $request->user();

        $q = Project::query()->whereNull('deleted_at')->whereNotNull('service_code');

        if ($user->isClientRole()) {
            $client = $request->attributes->get('portal_client') ?? Client::forUser($user);
            if (! $client) return response()->json([]);
            $q->where('client_id', $client->id);
        } elseif ($user->isGalvanizer()) {
            $rf = $request->input('role_filter');
            $q->where($this->galvanizerWhereClause($user, $rf));
        } elseif (in_array($user->role, ['partner', 'director'], true)) {
            $rf = $request->input('role_filter');
            if ($rf && $rf !== 'all') {
                $q->where($this->analystWhereClause($user, $rf));
            }
        } elseif ($user->role === 'associate') {
            $q->where($this->analystWhereClause($user, $request->input('role_filter')));
        }

        $counts = $q->selectRaw('UPPER(service_code) as svc, COUNT(*) as cnt')
            ->whereNotIn('status', ['Closed', 'Abandoned', 'Refused'])
            ->groupBy('svc')
            ->pluck('cnt', 'svc');

        return response()->json($counts);
    }

    public function show(Request $request, $id)
    {
        $project = Project::with('client', 'partner', 'manager', 'stages.owner', 'tasks.assignee')->findOrFail($id);

        $this->authorize('view', $project);

        return response()->json($project);
    }

    public function workspace(Request $request, $id, MatterWorkspaceService $workspace)
    {
        $project = Project::findOrFail($id);
        $this->authorize('view', $project);

        return new MatterWorkspaceResource($workspace->build($project, $request->user()));
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
        $validated = app(DocketNumberService::class)->assignForCreation($validated);
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

            // Auto-extract service_code from manually-entered docket (format: 4+3+2+3 = 12 chars)
            if (empty($validated['service_code']) && strlen($canonicalId) >= 12) {
                $validated['service_code'] = strtoupper(substr($canonicalId, 9, 3));
            }
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

                // Auto-extract service_code from manually-typed docket (format: 4+3+2+3 = 12 chars)
                if (empty($validated['service_code']) && strlen($manualCanonicalId) >= 12) {
                    $validated['service_code'] = strtoupper(substr($manualCanonicalId, 9, 3));
                }
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
        $family = app(InventionFamilyService::class)->attach($project);

        // Anchor Indian patent matters to a patent_application (the legal entity).
        // Chained matters share their predecessor's application via parent_project_id.
        $svc = strtoupper($validated['service_code'] ?? '');
        $indiaPatentSvcs = [
            'PRV', 'CPT', 'CPD', 'CVP', 'CPE', 'PCT', 'NAP', 'NPE', 'NAF', 'NPA',
            'DVA', 'PAD', '9EP', '98A', '18F', '18A', 'FER', 'SER', 'TER',
            'HRG', 'GRT', 'RNF', 'OPP', 'PGO', '27F', 'ROA', 'ERH', '24F',
            'RPO', 'ABN', 'WDR',
        ];
        if (in_array($svc, $indiaPatentSvcs, true) && (empty($project->patent_office_code) || $project->patent_office_code === 'IN')) {
            $parentAppId = $project->parent_project_id
                ? Project::where('id', $project->parent_project_id)->value('patent_application_id')
                : null;
            $appId = $parentAppId ?: \App\Models\PatentApplication::create([
                'invention_family_id' => $family->id,
                'client_id'          => $project->client_id,
                'application_number' => $project->application_number,
                'title'              => $project->invention_title ?: $project->project_name,
                'priority_date'      => $project->priority_date,
                'filing_date'        => $project->filing_date,
                'legal_status'       => 'Pending',
                'jurisdiction'       => 'IN',
            ])->id;
            $project->update(['patent_application_id' => $appId]);
        }

        // Seed pipeline stages based on service code
        $serviceStages = $this->stagesForServiceCode($svc);
        foreach ($serviceStages as $index => $stageName) {
            ProjectStage::create([
                'project_id'     => $project->id,
                'stage_name'     => $stageName,
                'status'         => $index === 0 ? 'In Progress' : 'Pending',
                'sequence_order' => $index,
                'duration_days'  => 15,
                'due_date'       => Carbon::now()->addDays(($index + 1) * 15),
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

        if (array_key_exists('patent_office_code', $validated) || array_key_exists('service_code', $validated)) {
            $validated = array_merge($validated, app(DocketNumberService::class)->recanonicalize(
                $project,
                $validated['patent_office_code'] ?? null,
                $validated['service_code'] ?? null,
            ));
        }

        $project->update($validated);

        // When status changes to a terminal state, clear the active pipeline stage.
        // Granted/Completed → mark all stages Completed (case is fully done).
        // Refused/Abandoned/Closed → clear the "In Progress" stage to Pending so
        // the workflow history is preserved and can be resumed if status is reopened.
        $terminalStatuses = ['Granted', 'Refused', 'Abandoned', 'Closed', 'Completed'];
        if (array_key_exists('status', $validated) && in_array($validated['status'], $terminalStatuses, true)) {
            $now = Carbon::now();
            if (in_array($validated['status'], ['Granted', 'Completed'], true)) {
                // Full completion — mark every stage done
                $project->stages()->where('status', '!=', 'Completed')->update([
                    'status'        => 'Completed',
                    'actual_end_at' => $now,
                ]);
                $project->stages()->whereNull('actual_start_at')->update(['actual_start_at' => $now]);
            } else {
                // Soft terminal (Refused/Abandoned/Closed) — just clear the active stage
                $project->stages()->where('status', 'In Progress')->update([
                    'status'        => 'Pending',
                    'actual_start_at' => null,
                    'actual_end_at'   => null,
                ]);
            }
            if ($validated['status'] === 'Granted') {
                $project->update(['patent_granted' => true]);
            }

            // Sync the application's legal status + auto-record docket events
            // (granted → renewal schedule + Rule 80(3) deadline; refused → review/appeal windows)
            $this->syncApplicationLegalStatus($project, $validated['status'], $user->id);
        }

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

        // Block stage changes when the case is in a terminal status (Closed/Completed allow workflow editing)
        $terminalStatuses = ['Granted', 'Refused', 'Abandoned'];
        if (in_array($project->status, $terminalStatuses, true)) {
            return response()->json([
                'message' => "Case is {$project->status} — no workflow stage is applicable. Change the project status first to resume the pipeline.",
                'terminal_status' => $project->status,
            ], 422);
        }

        $request->validate([
            'stage_name' => 'required|string',
            'status' => 'nullable|string', // Pending, In Progress, Completed
        ]);

        $stageName = $request->stage_name;
        $targetStage = ProjectStage::where('project_id', $project->id)->where('stage_name', $stageName)->first();

        // Stage not found — existing project has legacy stages; re-seed based on service_code.
        if (!$targetStage) {
            $this->reseedStages($project);
            $targetStage = ProjectStage::where('project_id', $project->id)->where('stage_name', $stageName)->firstOrFail();
        }

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

    // Public proxy so other controllers can call stagesForServiceCode without coupling
    public function stagesForCode(string $svc): array
    {
        return $this->stagesForServiceCode($svc);
    }

    private function stagesForServiceCode(string $svc): array
    {
        return match (true) {
            in_array($svc, ['PAS', 'SRH', 'PAT', 'FTO']) => [
                "Matter Created",
                "Inventor / Technology Disclosure Requested",
                "Disclosure Received",
                "Search Parameters Defined",
                "Prior Art Search In Progress",
                "Search Report Drafted",
                "Search Report Reviewed Internally",
                "Search Report Shared with Client",
            ],
            $svc === 'PRV' => [
                "Matter Created",
                "Inventor Disclosure Requested",
                "Inventor Disclosure Received",
                "Prior Art Search (Optional)",
                "Draft Started",
                "Draft Completed",
                "Internal Review",
                "Corrections Incorporated",
                "Partner Review",
                "Client Review",
                "Client Approved",
                "Forms Prepared (Form 1, 2, 3)",
                "Government Fees Calculated",
                "Filed with IPO",
                "Application Number Received",
                "Completed — CPT Deadline Set (12 months)",
            ],
            in_array($svc, ['CPT', 'CPE']) => [
                "Matter Created",
                "Inventor Disclosure Reviewed",
                "Claims Drafted",
                "Claims Shared with Client",
                "Claims Approved by Client",
                "Specification Drafting Started",
                "Draft Completed",
                "Internal Review",
                "Corrections Incorporated",
                "Partner Review",
                "Draft Shared with Client",
                "Client Feedback Received",
                "Revised Draft Completed",
                "Forms Prepared (Form 1, 2, 3)",
                "Government Fees Paid",
                "Filed with IPO",
                "Completed — Awaiting Publication",
            ],
            $svc === 'CPD' => [
                "Matter Created",
                "Inventor Disclosure Requested",
                "Inventor Disclosure Received",
                "Claims Drafted",
                "Claims Shared with Client",
                "Claims Approved by Client",
                "Specification Drafting Started",
                "Draft Completed",
                "Internal Review",
                "Corrections Incorporated",
                "Partner Review",
                "Draft Shared with Client",
                "Client Feedback Received",
                "Revised Draft Completed",
                "Forms Prepared (Form 1, 2, 3)",
                "Government Fees Paid",
                "Filed with IPO — Awaiting Publication",
            ],
            $svc === 'CVP' => [
                "Matter Created",
                "Priority Application Documents Received",
                "Priority Date Verified",
                "12-Month Deadline Confirmed",
                "Claims Drafted (adapted for Indian law)",
                "Specification Drafted",
                "Internal Review",
                "Partner Review",
                "Client Approval",
                "Forms Prepared (Form 1, 2, 3, 4 — Priority)",
                "Filed with IPO (within 12 months of priority)",
                "Completed — Awaiting Publication",
            ],
            $svc === 'PCT' => [
                "Matter Created",
                "Priority Date Verified",
                "International Application Drafted",
                "Receiving Office Selected (RO/IN or others)",
                "International Fees Calculated",
                "Application Filed at Receiving Office",
                "Filing Receipt / IB Reference Received",
                "International Search Report (ISR) Received",
                "Written Opinion Received",
                "Chapter II Examination (Optional)",
                "Client Review of ISR / Written Opinion",
                "National Phase Entry Deadline Set (India: 31 months from priority)",
                "International Publication Confirmed (18 months)",
                "Completed — National Phase Entry Pending",
            ],
            in_array($svc, ['NAP', 'NPE', 'NAF', 'NPA']) => [
                "Matter Created",
                "PCT Application Documents Received",
                "31-Month National Phase Deadline Verified",
                "National Phase Entry Decision Confirmed",
                "Translation Prepared (if required)",
                "National Phase Entry Application Drafted",
                "Claims Adapted for Indian Law",
                "Internal Review",
                "Partner Review",
                "Forms Prepared (Form 1, 2, 3 — National Phase)",
                "Government Fees Paid",
                "Filed with IPO (within 31 months)",
                "Application Number Received",
                "Completed — Awaiting Publication",
            ],
            $svc === 'DVA' => [
                "Matter Created",
                "Parent Application Identified",
                "Claims to Divide Identified",
                "Controller Objection / Invitation Noted",
                "Divisional Claims Drafted",
                "Specification Prepared",
                "Internal Review",
                "Partner Review",
                "Client Approval",
                "Forms Prepared (Form 1, 2)",
                "Government Fees Paid",
                "Filed with IPO — Linked to Parent",
                "Completed — Awaiting Publication",
            ],
            $svc === 'PAD' => [
                "Matter Created",
                "Parent Patent Identified",
                "Improvement / Addition Defined",
                "Addition Claims Drafted",
                "Claims Reviewed Internally",
                "Partner Review",
                "Client Approval",
                "Forms Prepared (Form 1, 2 — Addition)",
                "Government Fees Paid",
                "Filed with IPO",
                "Application Number Received",
                "Completed — Awaiting Publication",
            ],
            in_array($svc, ['9EP', '98A']) => [
                "Application Filed and Priority Date Recorded",
                "Publication Date Calculated (18 months from earliest priority — S.11A)",
                "Early Publication Requested (Form 9 — optional)",
                "Published in Official Journal",
                "Publication Number Confirmed",
                "Completed — Ready for Examination Request",
            ],
            $svc === '18F' => [
                "Application Published (18F Trigger)",
                "RFE Deadline Docketed (31 months from earliest priority; 48 months if filed before 15.03.2024)",
                "Examination Request Decision Made",
                "Form 18 Prepared",
                "Government Fee Calculated",
                "RFE Filed with IPO",
                "Completed — Awaiting First Examination Report",
            ],
            $svc === '18A' => [
                "Application Published (18A Trigger)",
                "RFE Deadline Docketed (31 months from earliest priority; 48 months if filed before 15.03.2024)",
                "Grounds for Acceleration Verified (Rule 24C eligibility)",
                "Examination Request Decision Made",
                "Form 18A Prepared",
                "Government Fee Calculated",
                "RFE Filed with IPO",
                "Completed — Awaiting First Examination Report (Expedited)",
            ],
            in_array($svc, ['FER', 'SER', 'TER']) => [
                "Examination Report Received",
                "Response Deadline Docketed (6 months from FER; +3 months via Form 4 — Rule 24B)",
                "Objections Analyzed",
                "Response Strategy Formulated",
                "Claims Amended / Arguments Drafted",
                "Internal Review",
                "Partner Review",
                "Client Communicated",
                "Response Filed (Form 3 / 13)",
                "Completed — Awaiting Controller Decision",
            ],
            $svc === 'HRG' => [
                "Hearing Notice Received",
                "Hearing Date Set (max 2 adjournments of 30 days each — Rule 129A)",
                "Arguments Prepared",
                "Prior Art / Documents Compiled",
                "Internal Review",
                "Partner Review",
                "Hearing Attended",
                "Written Submissions Filed (within 15 days of hearing — Rule 28(7))",
                "Awaiting Hearing Order",
            ],
            $svc === 'GRT' => [
                "Grant Order Received",
                "Patent Certificate Issued",
                "Patent Number Recorded",
                "Accumulated Renewal Fees Docketed (due 3 months from grant recordal — Rule 80(3))",
                "Renewal Schedule Set (Years 3–20)",
                "Form 27 Schedule Set (once every 3 financial years)",
                "Completed — Patent Active",
            ],
            $svc === 'RNF' => [
                "Renewal Year Identified",
                "Renewal Fee Due Date Confirmed",
                "Renewal Decision Made by Client",
                "Renewal Fee Paid",
                "Completed — Next Renewal Set",
            ],
            $svc === 'RPO' => [
                "Patent Lapse Identified (renewal fee missed — S.53)",
                "Restoration Window Verified (18 months from lapse — S.60)",
                "Restoration Petition Prepared (Form 15)",
                "Evidence of Unintentional Lapse Compiled",
                "Restoration Petition Filed",
                "Controller Decision Received",
                "Completed — Patent Restored or Ceased",
            ],
            $svc === 'ABN' => [
                "Abandonment Trigger Identified (missed response deadline — S.21(1))",
                "Rule 138 Extension Window Evaluated (up to 6 months)",
                "Client Advised of Options",
                "Extension Petition Filed / Matter Closed",
                "Completed — Restored to Prosecution or Abandoned",
            ],
            $svc === 'PGO' => [
                "Pre-Grant Opposition Received / Filed (S.25(1))",
                "Representation Analyzed",
                "Reply Statement Drafted (within 2 months of notice — Rule 55(4))",
                "Evidence Prepared",
                "Reply Filed with IPO",
                "Hearing Scheduled (if requested)",
                "Hearing Attended",
                "Controller Order Received",
                "Completed — Application Proceeds or Refused",
            ],
            $svc === 'WDR' => [
                "Withdrawal Decision by Client",
                "Pre-Publication Check (withdraw before publication to preserve secrecy — S.11B(4))",
                "Withdrawal Request Prepared",
                "Withdrawal Request Filed",
                "Withdrawal Recorded by IPO",
                "Completed — Application Withdrawn",
            ],
            $svc === 'OPP' => [
                "Post-Grant Opposition Filed / Received (S.25(2) — within 12 months of grant publication)",
                "Opposition Petition Analyzed",
                "Reply Statement Drafted",
                "Evidence Affidavit Prepared",
                "Evidence of Opponent Received",
                "Evidence Reply Prepared",
                "Hearing Scheduled",
                "Hearing Arguments Prepared",
                "Hearing Attended",
                "Order Received",
                "Completed — Patent Maintained or Revoked",
            ],
            $svc === '27F' => [
                "Form 27 Due Date Identified (once every 3 financial years)",
                "Working Statement Prepared",
                "Client Approval",
                "Form 27 Filed",
            ],
            $svc === 'ROA' => [
                "Refusal Order Received",
                "Review Petition Evaluated (S.77(1)(f) — within 1 month)",
                "Appeal Decision Made (High Court — S.117A)",
                "Completed — Review/Appeal Filed or Matter Closed",
            ],
            $svc === 'ERH' => [
                "Appeal Decision Made",
                "Appeal Filed at High Court (S.117A)",
                "Grounds of Appeal Prepared",
                "Counter-Statement by Respondent Received",
                "Reply Filed",
                "Oral Arguments Scheduled",
                "Hearing Attended",
                "Judgment / Order Received",
                "Completed — Decision",
            ],
            $svc === '24F' => [
                "Revocation Petition Received",
                "Reply Statement Prepared",
                "Evidence Filed",
                "Counter-Evidence Received",
                "Hearing Scheduled",
                "Hearing Attended",
                "Order Received",
                "Completed — Patent Maintained or Revoked",
            ],
            default => [
                "Matter Created",
                "Documentation Received",
                "Work In Progress",
                "Internal Review",
                "Partner Review",
                "Client Approval",
                "Filing / Submission",
                "Completed",
            ],
        };
    }

    /**
     * Work status (matter) → legal status (application) sync.
     * Granted/Refused also auto-record docket events so statutory
     * deadlines (Rule 80(3) renewals, S.77(1)(f)/S.117A windows) are generated.
     */
    private function syncApplicationLegalStatus(Project $project, string $workStatus, int $userId): void
    {
        if (!$project->patent_application_id) {
            return;
        }
        $app = \App\Models\PatentApplication::find($project->patent_application_id);
        if (!$app) {
            return;
        }

        try {
            match ($workStatus) {
                'Granted' => \App\Support\DocketRules::recordEvent(
                    'granted', Carbon::now(), $project->id, $app->id, 'Auto: project status set to Granted', $userId
                ),
                'Refused' => \App\Support\DocketRules::recordEvent(
                    'refused', Carbon::now(), $project->id, $app->id, 'Auto: project status set to Refused', $userId
                ),
                'Abandoned' => $app->update(['legal_status' => 'Abandoned']),
                default     => null,
            };
        } catch (\Throwable $e) {
            report($e); // legal-status sync must never break the status update itself
        }
    }

    private function reseedStages(Project $project): void
    {
        $svc = strtoupper($project->service_code ?? '');
        $stageNames = $this->stagesForServiceCode($svc);

        // Find the currently active stage name before wiping, to preserve progress.
        $currentActive = $project->stages()->where('status', 'In Progress')->value('stage_name');
        $completedNames = $project->stages()->where('status', 'Completed')->pluck('stage_name')->toArray();

        $project->stages()->delete();

        foreach ($stageNames as $index => $stageName) {
            $status = 'Pending';
            if ($stageName === $currentActive) {
                $status = 'In Progress';
            } elseif (in_array($stageName, $completedNames)) {
                $status = 'Completed';
            }
            ProjectStage::create([
                'project_id'     => $project->id,
                'stage_name'     => $stageName,
                'status'         => $status,
                'sequence_order' => $index,
                'duration_days'  => 15,
                'due_date'       => Carbon::now()->addDays(($index + 1) * 15),
            ]);
        }
    }

    // ── Service order for chain sorting ──────────────────────────────────────
    private const SERVICE_ORDER = [
        'PAS' => 1, 'SRH' => 1, 'PAT' => 1, 'FTO' => 1,
        'PRV' => 2,
        'CPT' => 3, 'CPD' => 3, 'CVP' => 3, 'CPE' => 3,
        'PCT' => 3, 'NAP' => 3, 'NPE' => 3, 'NAF' => 3, 'NPA' => 3,
        'DVA' => 3, 'PAD' => 3,
        '9EP' => 4, '98A' => 4, '18F' => 4, '18A' => 4,
        'FER' => 5, 'SER' => 5, 'TER' => 5,
        'HRG' => 6, 'PGO' => 6,
        'GRT' => 7,
        'RNF' => 8, 'OPP' => 8, '27F' => 8, 'ROA' => 8,
        'ERH' => 9, '24F' => 9, 'RPO' => 9, 'ABN' => 9, 'WDR' => 9,
    ];

    // ── Valid elevation paths (Indian prosecution only) ────────────────────────
    private const ELEVATION_PATHS = [
        'PAS'  => ['PRV', 'CPD', 'CVP', 'PCT', 'DVA', 'PAD'],
        'SRH'  => ['PRV', 'CPD', 'CVP', 'PCT'],
        'PAT'  => ['PRV', 'CPD', 'CVP', 'PCT'],
        'FTO'  => ['PRV', 'CPD'],
        'PRV'  => ['CPT', 'WDR'],
        'CPT'  => ['9EP', '18F', 'WDR'],
        'CPD'  => ['9EP', '18F', 'WDR'],
        'CVP'  => ['CPE', '9EP', '18F', 'WDR'],
        'CPE'  => ['9EP', '18F', 'WDR'],
        'PCT'  => ['NAP', 'NPE', 'NAF'],
        'NAP'  => ['9EP', '18F', 'WDR'],
        'NPE'  => ['9EP', '18F', 'WDR'],
        'NAF'  => ['9EP', '18F', 'WDR'],
        'NPA'  => ['9EP', '18F', 'WDR'],
        'DVA'  => ['9EP', '18F', 'WDR'],
        'PAD'  => ['9EP', '18F', 'WDR'],
        '9EP'  => ['18F', '18A', 'PGO', 'WDR'],
        '98A'  => ['18F', '18A', 'PGO', 'WDR'],
        '18F'  => ['FER', 'PGO', 'WDR'],
        '18A'  => ['FER', 'PGO', 'WDR'],
        'FER'  => ['SER', 'HRG', 'GRT', 'ABN', 'PGO', 'WDR'],
        'SER'  => ['TER', 'HRG', 'GRT', 'ABN', 'PGO'],
        'TER'  => ['HRG', 'GRT', 'ABN', 'PGO'],
        'HRG'  => ['GRT', 'ROA', 'ABN'],
        'PGO'  => ['GRT', 'ROA'],
        'GRT'  => ['RNF', 'OPP', '27F', 'PAD', '24F'],
        'RNF'  => ['RNF', 'RPO'],
        'OPP'  => [],
        'ROA'  => ['ERH'],
        'ERH'  => [],
        '27F'  => [],
        'RPO'  => ['RNF'],
        'ABN'  => [],
        'WDR'  => [],
        '24F'  => [],
    ];

    public function elevate(Request $request, $id)
    {
        if (in_array($request->user()->role, ['client', 'client_admin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'to_service' => ['required', 'string', 'regex:/^[A-Za-z0-9]{3}$/'],
            'note'       => 'nullable|string|max:1000',
        ]);

        $project = Project::findOrFail($id);
        $this->authorize('update', $project);
        $successor = app(InventionFamilyService::class)->createBranch($project, [
            'patent_office_code' => $project->patent_office_code,
            'service_code' => strtoupper($data['to_service']),
            'note' => $data['note'] ?? null,
            'complete_source' => true,
        ], $request->user(), ['ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        Cache::increment('dashboard_v');

        return response()->json([
            'message' => "Created successor engagement {$successor->docket_number}.",
            'project' => $successor,
            'predecessor_id' => $project->id,
        ], 201);

        /** @noinspection PhpUnreachableStatementInspection */
        return DB::transaction(function () use ($request, $id, $data) {
            $project = Project::lockForUpdate()->findOrFail($id);
            $toSvc   = strtoupper($data['to_service']);

            // Derive fromSvc: use stored service_code, else last 3 chars of docket, else empty
            $storedSvc  = strtoupper($project->service_code ?? '');
            $docketSvc  = $project->docket_number ? strtoupper(substr($project->docket_number, -3)) : '';
            $fromSvc    = $storedSvc ?: $docketSvc;

            // No path restriction — user selects any service code freely.

            $fromDocket = $project->docket_number;

            // Freeze original_docket on first elevation
            if (!$project->original_docket) {
                $project->original_docket = $fromDocket;
            }

            // Build new docket: strip old service suffix (last 3 chars) and append new service code
            $newDocket = $fromDocket;
            if ($fromSvc && strlen($fromSvc) === 3 && str_ends_with(strtoupper($fromDocket ?? ''), $fromSvc)) {
                $newDocket = substr($fromDocket, 0, -3) . $toSvc;
            } else {
                // No recognisable suffix — just append (e.g. docket had no service code)
                $newDocket = ($fromDocket ?? '') . $toSvc;
            }

            $canonical = app(DocketNumberService::class)->recanonicalize($project, service: $toSvc);
            $newDocket = $canonical['docket_number'];

            $project->service_code   = $toSvc;
            $project->docket_number  = $newDocket;
            $project->project_code   = $newDocket;
            $project->invention_number = $canonical['invention_number'];
            $project->save();

            // Keep linked tracker row in sync with new docket and UIN
            \App\Models\TrackerRow::where('project_id', $project->id)
                ->update(['docket_number' => $newDocket, 'uin' => $newDocket]);

            $this->reseedStages($project);

            ProjectElevation::create([
                'project_id'           => $project->id,
                'predecessor_project_id' => null,
                'from_service_code'    => $fromSvc,
                'to_service_code'      => $toSvc,
                'from_docket'          => $fromDocket,
                'to_docket'            => $newDocket,
                'elevated_at'          => now(),
                'elevated_by_id'       => $request->user()->id,
                'note'                 => $data['note'] ?? null,
                'is_retroactive_link'  => false,
            ]);

            AuditLog::create([
                'user_id'      => $request->user()->id,
                'action'       => 'elevate',
                'subject_type' => 'Project',
                'subject_id'   => $project->id,
                'metadata'     => [
                    'from_service' => $fromSvc,
                    'to_service'   => $toSvc,
                    'from_docket'  => $fromDocket,
                    'to_docket'    => $newDocket,
                ],
                'ip_address'   => $request->ip(),
                'user_agent'   => $request->userAgent(),
            ]);

            Cache::increment('dashboard_v');

            return response()->json([
                'message' => "Elevated from {$fromSvc} to {$toSvc}",
                'project' => $project->fresh(['stages', 'client']),
            ]);
        });
    }

    public function linkPredecessor(Request $request, $id)
    {
        if (in_array($request->user()->role, ['client', 'client_admin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'predecessor_id' => 'required|integer|exists:projects,id',
            'note'           => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($request, $id, $data) {
            $project     = Project::findOrFail($id);
            $predecessor = Project::findOrFail($data['predecessor_id']);

            if ($predecessor->client_id !== $project->client_id) {
                throw ValidationException::withMessages([
                    'predecessor_id' => 'Predecessor must belong to the same client.',
                ]);
            }

            if ($project->id === $predecessor->id) {
                throw ValidationException::withMessages([
                    'predecessor_id' => 'A project cannot be its own predecessor.',
                ]);
            }

            // Guard: don't allow double-linking
            $alreadyLinked = ProjectElevation::where('project_id', $id)
                ->where('predecessor_project_id', $predecessor->id)
                ->exists();
            if ($alreadyLinked) {
                throw ValidationException::withMessages([
                    'predecessor_id' => 'These two projects are already linked.',
                ]);
            }

            ProjectElevation::create([
                'project_id'             => $project->id,
                'predecessor_project_id' => $predecessor->id,
                'from_service_code'      => strtoupper($predecessor->service_code ?? ''),
                'to_service_code'        => strtoupper($project->service_code ?? ''),
                'from_docket'            => $predecessor->docket_number,
                'to_docket'              => $project->docket_number,
                'elevated_at'            => $predecessor->created_at,
                'elevated_by_id'         => $request->user()->id,
                'note'                   => $data['note'] ?? null,
                'is_retroactive_link'    => true,
            ]);

            AuditLog::create([
                'user_id'      => $request->user()->id,
                'action'       => 'link_predecessor',
                'subject_type' => 'Project',
                'subject_id'   => $project->id,
                'metadata'     => [
                    'predecessor_id'     => $predecessor->id,
                    'predecessor_docket' => $predecessor->docket_number,
                ],
                'ip_address'   => $request->ip(),
                'user_agent'   => $request->userAgent(),
            ]);

            return response()->json(['message' => 'Predecessor linked successfully.']);
        });
    }

    /**
     * Scan all projects (scoped to requester) and detect unlinked docket chains.
     * A chain = multiple projects sharing the same docket prefix (docket minus last 3 chars)
     * belonging to the same client, with different service codes in a valid elevation order,
     * and NOT yet linked via project_elevations.
     */
    public function detectChains(Request $request)
    {
        if (in_array($request->user()->role, ['client', 'client_admin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Collect all already-linked project pairs so we don't re-link them
        $alreadyLinked = ProjectElevation::whereNotNull('predecessor_project_id')
            ->select('project_id', 'predecessor_project_id')
            ->get()
            ->mapWithKeys(fn ($e) => ["{$e->predecessor_project_id}-{$e->project_id}" => true]);

        $projects = Project::select('id', 'client_id', 'docket_number', 'service_code', 'created_at', 'project_name')
            ->whereNotNull('service_code')
            ->whereNotNull('docket_number')
            ->get();

        // Group by (client_id + docket_prefix) where prefix = docket minus last 3 chars
        $groups = [];
        foreach ($projects as $p) {
            $svc = strtoupper($p->service_code ?? '');
            $docket = $p->docket_number ?? '';
            // Only handle 3-char service codes appended to the docket
            if (strlen($svc) === 3 && str_ends_with(strtoupper($docket), $svc)) {
                $prefix = substr($docket, 0, -3);
                $key = "{$p->client_id}::{$prefix}";
                $groups[$key][] = $p;
            }
        }

        $chains = [];
        foreach ($groups as $key => $members) {
            if (count($members) < 2) continue;

            // Sort members by service order, then created_at
            usort($members, function ($a, $b) {
                $oa = self::SERVICE_ORDER[strtoupper($a->service_code)] ?? 99;
                $ob = self::SERVICE_ORDER[strtoupper($b->service_code)] ?? 99;
                if ($oa !== $ob) return $oa - $ob;
                return strcmp($a->created_at, $b->created_at);
            });

            // Build sequential pairs and filter out already-linked ones
            $pairs = [];
            for ($i = 0; $i < count($members) - 1; $i++) {
                $pred = $members[$i];
                $succ = $members[$i + 1];
                $pairKey = "{$pred->id}-{$succ->id}";
                if (!isset($alreadyLinked[$pairKey])) {
                    $pairs[] = [
                        'predecessor_id'      => $pred->id,
                        'predecessor_docket'  => $pred->docket_number,
                        'predecessor_service' => strtoupper($pred->service_code),
                        'successor_id'        => $succ->id,
                        'successor_docket'    => $succ->docket_number,
                        'successor_service'   => strtoupper($succ->service_code),
                    ];
                }
            }

            if (count($pairs) === 0) continue;

            [, $prefix] = explode('::', $key, 2);
            $chains[] = [
                'prefix'  => $prefix,
                'members' => collect($members)->map(fn ($p) => [
                    'id'           => $p->id,
                    'docket'       => $p->docket_number,
                    'service_code' => strtoupper($p->service_code),
                    'project_name' => $p->project_name,
                    'created_at'   => $p->created_at,
                ])->values(),
                'pairs'   => $pairs,
            ];
        }

        return response()->json(['chains' => $chains, 'total' => count($chains)]);
    }

    /**
     * Bulk-create retroactive elevation links for confirmed chains.
     * Request: { pairs: [{ predecessor_id, successor_id, note? }] }
     */
    public function bulkLinkChains(Request $request)
    {
        if (in_array($request->user()->role, ['client', 'client_admin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'pairs'         => 'required|array|min:1',
            'pairs.*.predecessor_id' => 'required|integer|exists:projects,id',
            'pairs.*.successor_id'   => 'required|integer|exists:projects,id',
            'pairs.*.note'           => 'nullable|string|max:500',
        ]);

        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($data, $request, &$created, &$skipped) {
            foreach ($data['pairs'] as $pair) {
                $predId = $pair['predecessor_id'];
                $succId = $pair['successor_id'];

                // Skip if already linked
                $exists = ProjectElevation::where('project_id', $succId)
                    ->where('predecessor_project_id', $predId)
                    ->exists();
                if ($exists) { $skipped++; continue; }

                $pred = Project::findOrFail($predId);
                $succ = Project::findOrFail($succId);

                if ($pred->client_id !== $succ->client_id) { $skipped++; continue; }

                ProjectElevation::create([
                    'project_id'             => $succId,
                    'predecessor_project_id' => $predId,
                    'from_service_code'      => strtoupper($pred->service_code ?? ''),
                    'to_service_code'        => strtoupper($succ->service_code ?? ''),
                    'from_docket'            => $pred->docket_number,
                    'to_docket'              => $succ->docket_number,
                    'elevated_at'            => $pred->created_at,
                    'elevated_by_id'         => $request->user()->id,
                    'note'                   => $pair['note'] ?? 'Retroactively linked from existing records',
                    'is_retroactive_link'    => true,
                ]);
                $created++;
            }

            if ($created > 0) {
                AuditLog::create([
                    'user_id'      => $request->user()->id,
                    'action'       => 'bulk_link_chains',
                    'subject_type' => 'Project',
                    'subject_id'   => 0,
                    'metadata'     => ['created' => $created, 'skipped' => $skipped],
                    'ip_address'   => $request->ip(),
                    'user_agent'   => $request->userAgent(),
                ]);
                Cache::increment('dashboard_v');
            }
        });

        return response()->json([
            'message' => "Linked {$created} pair(s)." . ($skipped ? " {$skipped} already linked or invalid." : ""),
            'created' => $created,
            'skipped' => $skipped,
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

        // Elevation history for this project
        $elevations = ProjectElevation::where('project_id', $project->id)
            ->with('elevatedBy:id,name')
            ->orderBy('elevated_at')
            ->get();

        // Collect predecessor chain project IDs (for cross-chain invoices)
        $predecessorIds = $elevations->whereNotNull('predecessor_project_id')
            ->pluck('predecessor_project_id')
            ->unique()
            ->values();

        // Invoices for this project
        $invoices = \App\Models\Invoice::where('project_id', $project->id)
            ->orderBy('created_at')
            ->get(['id', 'invoice_code', 'status', 'total_amount', 'balance_due',
                   'subtotal', 'tax_amount', 'currency', 'created_at', 'due_date']);

        // Chain invoices: invoices from predecessor projects, labeled with original docket
        $chainInvoices = collect();
        if ($predecessorIds->isNotEmpty()) {
            $predecessorProjects = Project::withTrashed()
                ->whereIn('id', $predecessorIds)
                ->get(['id', 'docket_number', 'service_code']);

            $chainInvoices = \App\Models\Invoice::whereIn('project_id', $predecessorIds)
                ->orderBy('created_at')
                ->get(['id', 'invoice_code', 'project_id', 'status', 'total_amount', 'balance_due',
                       'subtotal', 'tax_amount', 'currency', 'created_at', 'due_date'])
                ->map(function ($inv) use ($predecessorProjects) {
                    $proj = $predecessorProjects->firstWhere('id', $inv->project_id);
                    $arr = $inv->toArray();
                    $arr['source_docket']       = $proj?->docket_number;
                    $arr['source_service_code'] = $proj?->service_code;
                    return $arr;
                });
        }

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
            'chain_invoices'       => $chainInvoices,
            'ledger'               => $ledger,
            'elevations'           => $elevations,
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
