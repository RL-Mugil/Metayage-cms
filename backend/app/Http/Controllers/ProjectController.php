<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Http\PaginationHelper;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

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

        if ($user->role === 'client') {
            $base->whereHas('client.contacts', function ($q) use ($user) {
                $q->where('email', $user->email);
            });
        } elseif (in_array($user->role, ['associate', 'paralegal'])) {
            $base->where(function ($q) use ($user) {
                $q->where('assigned_manager_id', $user->id)
                  ->orWhere('assigned_partner_id', $user->id);
            });
        }

        $today = now()->toDateString();

        return response()->json([
            'total'       => (clone $base)->count(),
            'open'        => (clone $base)->where('status', 'Open')->count(),
            'in_progress' => (clone $base)->where('status', 'In Progress')->count(),
            'on_hold'     => (clone $base)->where('status', 'On Hold')->count(),
            'overdue'     => (clone $base)->whereNotNull('hard_deadline')
                                ->where('hard_deadline', '<', $today)
                                ->whereNotIn('status', ['Closed', 'Completed'])->count(),
        ]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Project::with('client', 'partner', 'manager', 'stages');

        // RBAC access filter
        if ($user->role === 'client') {
            $query->whereHas('client.contacts', function ($q) use ($user) {
                $q->where('email', $user->email);
            });
        } elseif (in_array($user->role, ['associate', 'paralegal'])) {
            // Associates can see projects assigned to them or their department
            $query->where(function ($q) use ($user) {
                $q->where('assigned_manager_id', $user->id)
                  ->orWhere('assigned_partner_id', $user->id)
                  ->orWhereJsonContains('assigned_team', $user->id);
            });
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('project_name', 'ilike', "%{$search}%")
                  ->orWhere('project_code', 'ilike', "%{$search}%")
                  ->orWhere('invention_title', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }

        if ($request->boolean('overdue')) {
            $today = now()->toDateString();
            $query->whereNotNull('hard_deadline')
                  ->where('hard_deadline', '<', $today)
                  ->whereNotIn('status', ['Closed', 'Completed']);
        }

        $sortBy  = in_array($request->sort_by, ['project_name', 'docket_number', 'status', 'hard_deadline', 'filing_date'])
            ? $request->sort_by : 'hard_deadline';
        $sortDir = $request->sort_dir === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortBy, $sortDir);

        return response()->json(PaginationHelper::paginate($query, $request));
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        $project = Project::with('client', 'partner', 'manager', 'stages.owner', 'tasks.assignee')->findOrFail($id);

        // RBAC validation
        if ($user->role === 'client') {
            $isAssociated = $project->client->contacts()->where('email', $user->email)->exists();
            if (! $isAssociated) {
                return response()->json(['message' => 'Unauthorized Access'], 403);
            }
        }

        return response()->json($project);
    }

    public function store(StoreProjectRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        $validated['assigned_partner_id'] = $validated['assigned_partner_id'] ?? $user->id;
        $validated['assigned_manager_id'] = $validated['assigned_manager_id'] ?? $user->id;

        $project = \DB::transaction(function () use ($validated) {
            // Sequential project code, row-locked so concurrent requests
            // cannot generate duplicates (project_code is unique).
            $year = date('Y');
            $last = Project::where('project_code', 'like', "PRJ-{$year}-%")
                ->orderBy('project_code', 'desc')
                ->lockForUpdate()
                ->value('project_code');
            $seq = $last ? ((int) substr($last, -5)) + 1 : 10000;
            $validated['project_code'] = sprintf('PRJ-%s-%05d', $year, $seq);
            $validated['status'] = 'Open';

            // Auto-generate docket number: {ClientCode}{Seq:3}{OfficeCode}{ServiceCode}
            $client = Client::findOrFail($validated['client_id']);
            $clientCode = $client->client_code ?? '';
            $maxSeq = 0;
            foreach (Project::where('client_id', $validated['client_id'])->whereNotNull('docket_number')->lockForUpdate()->pluck('docket_number') as $dn) {
                if (strlen($dn) >= strlen($clientCode) + 3) {
                    $seq = (int) substr($dn, strlen($clientCode), 3);
                    if ($seq > $maxSeq) $maxSeq = $seq;
                }
            }
            $seq        = str_pad($maxSeq + 1, 3, '0', STR_PAD_LEFT);
            $office     = strtoupper($validated['patent_office_code'] ?? '');
            $service    = strtoupper($validated['service_code'] ?? '');
            $validated['docket_number'] = $clientCode . $seq . $office . $service;

            $project = Project::create($validated);

            // Seed default pipeline stages for new projects
            $defaultStages = ["Intake", "Drafting", "Filing", "Examination", "Object received", "Granted", "Renewal"];
            foreach ($defaultStages as $index => $stageName) {
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
        });

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

        return response()->json($project, 201);
    }

    public function update(UpdateProjectRequest $request, $id)
    {
        $user = $request->user();
        $project = Project::findOrFail($id);
        $validated = $request->validated();

        $project->update($validated);

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

        return response()->json($project);
    }

    public function updateStage(Request $request, $id)
    {
        $user = $request->user();
        $project = Project::findOrFail($id);

        if (! in_array($user->role, ['super_admin', 'partner', 'manager']) && $project->assigned_manager_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
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

        // Generate notification
        Notification::create([
            'user_id' => $project->assigned_manager_id,
            'title' => 'Case Stage Updated',
            'message' => "Case '{$project->project_name}' has been moved to '{$stageName}' stage.",
            'is_read' => false,
            'action_url' => "/projects/{$project->id}"
        ]);

        return response()->json([
            'message' => "Project stage updated to {$stageName}",
            'project' => Project::with('stages')->find($project->id)
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, ['super_admin', 'partner'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $project = Project::findOrFail($id);
        $project->delete();
        return response()->json(['message' => 'Case deleted']);
    }
}
