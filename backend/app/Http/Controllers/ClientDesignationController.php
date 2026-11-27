<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientDesignation;
use App\Models\User;
use App\Services\ClientPortalUsers;
use App\Support\ClientAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A client's portal designations and their access checkboxes. The firm (Client Portal page,
 * client panel Users tab) works on /portal/clients/{id}/designations; a client's own users
 * with "Manage users & designations" work on /my-portal/designations.
 */
class ClientDesignationController extends Controller
{
    /** The client this request is about, after checking who may manage it. */
    private function clientFor(Request $request, ?int $clientId): Client
    {
        $user = $request->user();
        if ($user->isClientRole()) {
            abort_unless(ClientAccess::can($user, 'manage_users'), 403, 'Your portal access does not include managing users.');
            $client = $request->attributes->get('portal_client') ?? Client::forUser($user);
            abort_unless($client && ($clientId === null || $clientId === (int) $client->id), 403);

            return $client;
        }
        abort_unless(in_array($user->role, ClientPortalUsers::MANAGE_ROLES, true), 403, 'Only staff who manage portal logins can change designations.');

        return Client::findOrFail((int) $clientId);
    }

    public function index(Request $request, ?int $id = null): JsonResponse
    {
        $client = $this->clientFor($request, $id);
        $counts = User::where('client_designation_id', '!=', null)->whereIn('id', $client->portalUserIds() ?: [0])
            ->selectRaw('client_designation_id, COUNT(*) AS n')->groupBy('client_designation_id')->pluck('n', 'client_designation_id');

        return response()->json([
            'permissions' => collect(ClientAccess::PERMISSIONS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'designations' => ClientDesignation::forClient($client->id)->get()->map(fn (ClientDesignation $d) => [
                'id' => $d->id, 'name' => $d->name, 'permissions' => ClientAccess::clean((array) $d->permissions),
                'ready_made' => $d->client_id === null, 'is_inventor' => (bool) $d->is_inventor, 'users' => (int) ($counts[$d->id] ?? 0),
            ]),
        ]);
    }

    public function store(Request $request, ?int $id = null): JsonResponse
    {
        $client = $this->clientFor($request, $id);
        $data = $this->validated($request, $client);
        $row = ClientDesignation::create(['client_id' => $client->id, 'name' => $data['name'], 'permissions' => ClientAccess::clean($data['permissions']),
            'created_by_id' => $request->user()->id]);
        $this->audit($request, 'client_designation_created', $client, $row);

        return response()->json(['id' => $row->id, 'name' => $row->name, 'permissions' => $row->permissions], 201);
    }

    public function update(Request $request, int $designationId, ?int $id = null): JsonResponse
    {
        $client = $this->clientFor($request, $id);
        $row = ClientDesignation::where('client_id', $client->id)->findOrFail($designationId);   // ready-made ones are fixed
        $data = $this->validated($request, $client, $row->id);
        $row->update(['name' => $data['name'], 'permissions' => ClientAccess::clean($data['permissions'])]);
        // Logins on this designation follow its boxes (and the role that matches them).
        $role = ClientAccess::roleFor($row->permissions);
        User::where('client_designation_id', $row->id)->where('role', '!=', 'inventor')->where('id', '!=', $request->user()->id)->update(['role' => $role]);
        $this->audit($request, 'client_designation_updated', $client, $row);

        return response()->json(['id' => $row->id, 'name' => $row->name, 'permissions' => $row->permissions]);
    }

    public function destroy(Request $request, int $designationId, ?int $id = null): JsonResponse
    {
        $client = $this->clientFor($request, $id);
        $row = ClientDesignation::where('client_id', $client->id)->findOrFail($designationId);
        abort_if(User::where('client_designation_id', $row->id)->exists(), 422, 'Move the users on this designation to another one first.');
        $this->audit($request, 'client_designation_deleted', $client, $row);
        $row->delete();

        return response()->json(['ok' => true]);
    }

    /** Change one login's designation (its access). */
    public function assign(Request $request, int $userId, ?int $id = null): JsonResponse
    {
        $client = $this->clientFor($request, $id);
        $data = $request->validate(['designation_id' => 'required|integer']);
        $designation = ClientDesignation::forClient($client->id)->whereKey($data['designation_id'])->firstOrFail();

        return response()->json(app(ClientPortalUsers::class)->changeDesignation($client, User::findOrFail($userId), $designation, $request->user(), $request->ip()));
    }

    // Firm side (/portal/clients/{id}/...): the client id comes first in the URL.
    public function staffIndex(Request $request, int $id): JsonResponse { return $this->index($request, $id); }

    public function staffStore(Request $request, int $id): JsonResponse { return $this->store($request, $id); }

    public function staffUpdate(Request $request, int $id, int $designationId): JsonResponse { return $this->update($request, $designationId, $id); }

    public function staffDestroy(Request $request, int $id, int $designationId): JsonResponse { return $this->destroy($request, $designationId, $id); }

    public function staffAssign(Request $request, int $id, int $userId): JsonResponse { return $this->assign($request, $userId, $id); }

    private function validated(Request $request, Client $client, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::notIn(array_keys(ClientAccess::PRESETS)),
                Rule::unique('client_designations', 'name')->where('client_id', $client->id)->ignore($ignoreId)],
            'permissions' => 'present|array', 'permissions.*' => ['string', Rule::in(array_keys(ClientAccess::PERMISSIONS))],
        ], ['name.not_in' => 'That name is a ready-made designation; pick another name.']);
        abort_if($data['permissions'] === [], 422, 'Tick at least one access box.');

        return $data;
    }

    private function audit(Request $request, string $action, Client $client, ClientDesignation $row): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'action' => $action, 'subject_type' => 'Client', 'subject_id' => $client->id,
            'metadata' => ['designation_id' => $row->id, 'name' => $row->name, 'permissions' => $row->permissions, 'client_id' => $client->id],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
    }
}
