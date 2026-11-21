<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveInventorRequest;
use App\Models\AuditLog;
use App\Models\Inventor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Firm-wide inventor records and the inventors named on each case. Staff add an
 * inventor once (with every Form 1 particular) and pick them again on later cases.
 * A portal login is optional and created separately (grantPortalAccess).
 */
class InventorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Inventor::class);
        $search = trim((string) $request->query('search', ''));

        $inventors = Inventor::query()
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where(fn ($q) => $q->where('full_name', 'ilike', $like)->orWhere('email', 'ilike', $like));
            })
            ->orderBy('full_name')->limit(20)->get();

        return response()->json($inventors->map->toSummary()->values());
    }

    public function store(SaveInventorRequest $request): JsonResponse
    {
        $inventor = DB::transaction(function () use ($request) {
            $inventor = Inventor::create($request->validated() + ['created_by_id' => $request->user()->id]);
            $this->audit($request, 'create_inventor', $inventor->id, ['full_name' => $inventor->full_name]);

            return $inventor;
        });

        return response()->json($inventor->toSummary(), 201);
    }

    public function update(SaveInventorRequest $request, $id): JsonResponse
    {
        $inventor = Inventor::findOrFail($id);
        DB::transaction(function () use ($request, $inventor) {
            $inventor->update($request->validated());
            $this->audit($request, 'update_inventor', $inventor->id, ['fields' => array_keys($request->validated())]);
        });

        return response()->json($inventor->fresh()->toSummary());
    }

    /**
     * The inventors named on a case, in filing order. Staff get the full particulars;
     * anyone else who can see the case (client portal, inventors) gets names only.
     */
    public function forProject(Request $request, $projectId): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $this->authorize('view', $project);
        $staff = $request->user()->can('viewAny', Inventor::class);

        return response()->json($project->inventors()->get()->map(fn (Inventor $inventor) => $staff
            ? $inventor->toSummary()
            : ['id' => $inventor->id, 'full_name' => $inventor->full_name])->values());
    }

    /** Replace a case's inventors (ordered ids) — used by the Forms tab. */
    public function syncProject(Request $request, $projectId): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        abort_unless($request->user()->can('update', $project) || $request->user()->can('prepareIpoForms', $project), 403);
        $validated = $request->validate([
            'inventor_ids'   => 'present|array|max:50',
            'inventor_ids.*' => 'integer|exists:inventors,id',
        ]);

        DB::transaction(function () use ($request, $project, $validated) {
            $project->syncInventors($validated['inventor_ids']);
            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'set_project_inventors',
                'subject_type' => 'Project', 'subject_id' => $project->id,
                'metadata' => ['inventor_ids' => $validated['inventor_ids']],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
        });

        return response()->json($project->inventors()->get()->map->toSummary()->values());
    }

    /** Creates an `inventor`-role login for this inventor (password shared directly, no email). */
    public function grantPortalAccess(Request $request, $id): JsonResponse
    {
        $inventor = Inventor::findOrFail($id);
        $this->authorize('grantPortalAccess', $inventor);
        abort_if($inventor->user_id !== null, 422, 'This inventor already has portal access.');
        $validated = $request->validate([
            'email'    => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|max:100',
        ]);

        $user = DB::transaction(function () use ($request, $inventor, $validated) {
            $user = User::create([
                'name' => $inventor->full_name, 'email' => $validated['email'],
                'password' => Hash::make($validated['password']), 'role' => 'inventor', 'status' => 'Active',
            ]);
            $inventor->update(['user_id' => $user->id, 'email' => $inventor->email ?: $validated['email']]);
            $this->audit($request, 'grant_inventor_portal', $inventor->id, ['user_id' => $user->id, 'email' => $user->email]);

            return $user;
        });

        return response()->json(['ok' => true, 'user' => $user->only(['id', 'name', 'email'])], 201);
    }

    private function audit(Request $request, string $action, int $inventorId, array $metadata): void
    {
        AuditLog::create([
            'user_id'      => $request->user()->id,
            'action'       => $action,
            'subject_type' => 'Inventor',
            'subject_id'   => $inventorId,
            'metadata'     => $metadata,
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);
    }
}
