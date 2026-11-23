<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveInventorRequest;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Inventor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\ClientPortalUsers;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Firm-wide inventor records and the inventors named on each case. Staff add an
 * inventor once (with every Form 1 particular) and pick them again on later cases.
 * A portal login is optional and created separately (grantPortalAccess).
 */
class InventorController extends Controller
{
    /**
     * With client_id: that client's inventors (all of them, filtered by search). With
     * other_clients=1 as well: search every other client's inventors. Without client_id:
     * a firm-wide search. Searches return at most 20 rows.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Inventor::class);
        $search = trim((string) $request->query('search', ''));
        $clientId = $request->integer('client_id') ?: null;
        $others = $request->boolean('other_clients');

        $query = Inventor::query()->with('client:id,client_code,legal_name,company_name')->withCount('projects');
        if ($clientId && ! $others) {
            $query->where('client_id', $clientId);
        } elseif ($clientId) {
            $query->where(fn ($q) => $q->where('client_id', '!=', $clientId)->orWhereNull('client_id'));
        }
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn ($q) => $q->where('full_name', 'ilike', $like)->orWhere('email', 'ilike', $like));
        }
        if (! $clientId || $others || $search !== '') {
            $query->limit(20);
        }

        return response()->json($query->orderBy('full_name')->get()->map->toSummary()->values());
    }

    public function store(SaveInventorRequest $request): JsonResponse
    {
        $client = Client::findOrFail($request->validated('client_id'));
        $this->authorize('view', $client);
        $data = $request->validated();
        $password = $data['portal_password'] ?? null;
        $linked = null;
        unset($data['portal_password']);

        if (! empty($data['user_id'])) {
            // A portal user of this client picked from the name list: the inventor uses their login.
            $linked = User::findOrFail($data['user_id']);
            if (! in_array($linked->role, User::CLIENT_ROLES, true) || ! app(ClientPortalUsers::class)->belongsTo($client, $linked)) {
                throw ValidationException::withMessages(['user_id' => 'Pick one of this client\'s portal users.']);
            }
            $data['email'] = $linked->email;
            $data['phone'] = $data['phone'] ?? $linked->mobile;
        } elseif ($password !== null) {
            // A new person: their inventor-role login is created with the record.
            $this->authorize('grantPortalAccess', new Inventor());
        }

        [$inventor, $newLogin] = DB::transaction(function () use ($request, $data, $password, $linked) {
            $newLogin = null;
            if ($password !== null) {
                $newLogin = User::create([
                    'name' => $data['full_name'], 'email' => $data['email'], 'password' => Hash::make($password),
                    'role' => 'inventor', 'status' => 'Active', 'mobile' => $data['phone'] ?? null,
                ]);
                $data['user_id'] = $newLogin->id;
            }
            $inventor = Inventor::create($data + ['created_by_id' => $request->user()->id]);
            $this->audit($request, 'create_inventor', $inventor->id, [
                'full_name' => $inventor->full_name, 'client_id' => $inventor->client_id,
                'user_id' => $inventor->user_id, 'login' => $newLogin ? 'created' : ($linked ? 'existing' : null),
            ]);

            return [$inventor, $newLogin];
        });

        if ($newLogin) {
            ClientPortalUsers::sendInvite($client, $newLogin, $password);
        }

        return response()->json($inventor->fresh('client')->toSummary(), 201);
    }

    public function update(SaveInventorRequest $request, $id): JsonResponse
    {
        $inventor = Inventor::findOrFail($id);
        $nationalityBefore = $inventor->nationality;
        DB::transaction(function () use ($request, $inventor) {
            $inventor->update($request->validated());
            $this->audit($request, 'update_inventor', $inventor->id, ['fields' => array_keys($request->validated())]);
        });
        if ($inventor->nationality !== $nationalityBefore) {
            app(\App\Services\ForeignFilingPermission::class)->syncForInventor($inventor);
        }

        return response()->json($inventor->fresh('client')->toSummary());
    }

    /** Deletes an inventor who is on no case; a portal login they had is deactivated. */
    public function destroy(Request $request, $id): JsonResponse
    {
        $inventor = Inventor::findOrFail($id);
        $this->authorize('delete', $inventor);

        $dockets = $inventor->projects()->get()->map(fn (Project $p) => $p->docket_number ?: $p->project_code)->filter()->values();
        if ($dockets->isNotEmpty()) {
            return response()->json([
                'message' => 'Remove this inventor from these cases first: '.$dockets->implode(', ').'.',
                'cases'   => $dockets,
            ], 422);
        }

        DB::transaction(function () use ($request, $inventor) {
            // Only an inventor-role login goes; a client user picked as inventor keeps their access.
            if ($inventor->user_id) {
                User::whereKey($inventor->user_id)->where('role', 'inventor')->update(['status' => 'Inactive']);
            }
            $this->audit($request, 'delete_inventor', $inventor->id, ['full_name' => $inventor->full_name, 'client_id' => $inventor->client_id, 'user_id' => $inventor->user_id]);
            $inventor->delete();
        });

        return response()->json(['ok' => true]);
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
            app(\App\Services\ForeignFilingPermission::class)->sync($project);
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
        // The inventor's saved email is their login unless another one is given.
        if (blank($request->input('email')) && filled($inventor->email)) {
            $request->merge(['email' => $inventor->email]);
        }
        $validated = $request->validate([
            'email'    => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|max:100',
        ], ['email.required' => "Add the inventor's email first."]);

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
