<?php

namespace App\Http\Controllers;

use App\Mail\PortalInviteMail;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PortalController extends Controller
{
    private const MANAGE_ROLES = ['super_admin', 'partner', 'manager'];

    private function denyUnauthorized(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (! in_array($request->user()->role, self::MANAGE_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }

    /**
     * Resolve every portal account tied to a client set, including the
     * primary admin on clients.portal_user_id and any secondary contact users.
     */
    private function portalUserIdsForClients(array $clientIds): array
    {
        $contactEmails = DB::table('client_contacts')
            ->whereIn('client_id', $clientIds)
            ->pluck('email')
            ->filter();

        $ids = User::query()
            ->whereIn('role', User::CLIENT_ROLES)
            ->when($contactEmails->isNotEmpty(), fn ($query) => $query->whereIn('email', $contactEmails))
            ->pluck('id');

        $primaryIds = Client::whereIn('id', $clientIds)
            ->whereNotNull('portal_user_id')
            ->pluck('portal_user_id');

        return $ids
            ->merge($primaryIds)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Immediately log out all portal users of the given clients:
     * revoke Sanctum tokens and kill their DB-backed sessions.
     */
    private function revokePortalAccess(array $clientIds): void
    {
        $userIds = $this->portalUserIdsForClients($clientIds);
        if ($userIds === []) return;

        DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Models\\User')
            ->whereIn('tokenable_id', $userIds)
            ->delete();
        DB::table('sessions')->whereIn('user_id', $userIds)->delete();
    }

    public function clients(Request $request)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        return response()->json(
            Client::whereNotNull('portal_user_id')->orderBy('company_name')->limit(500)->get()->map(fn ($c) => [
                'id'               => $c->id,
                'client_code'      => $c->client_code,
                'company_name'     => $c->company_name ?? $c->legal_name,
                'portal_enabled'   => (bool) $c->portal_enabled,
                'portal_invited_at'=> $c->portal_invited_at?->toDateTimeString(),
                'portal_user_id'   => $c->portal_user_id,
            ])
        );
    }

    public function toggle(Request $request, $id)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $client = Client::findOrFail($id);
        $client->portal_enabled = ! $client->portal_enabled;
        if ($client->portal_enabled && ! $client->portal_invited_at) {
            $client->portal_invited_at = now();
        }
        $client->save();

        // Disabling a portal logs its users out immediately.
        if (! $client->portal_enabled) {
            $this->revokePortalAccess([$client->id]);
        }

        return response()->json(['ok' => true, 'portal_enabled' => $client->portal_enabled]);
    }

    /** Mark all portal-disabled clients invited and notify the actor. */
    public function inviteAll(Request $request)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $count = Client::where('portal_enabled', false)->update([
            'portal_invited_at' => now(),
            'portal_enabled'    => true,
        ]);

        DB::table('ip_notifications')->insert([
            'user_id'     => $request->user()->id,
            'type'        => 'portal_invite',
            'title'       => 'Portal invitations sent',
            'description' => "Invitations recorded for {$count} inactive clients",
            'meta'        => json_encode(['count' => $count]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return response()->json(['ok' => true, 'invited' => $count]);
    }

    /**
     * Create a client portal account (one root admin user).
     * The firm admin sets the password and shares it directly with the client.
     */
    public function create(Request $request)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $validated = $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'name'      => 'nullable|string|max:255',
            'email'     => 'required|email|max:255',
            'password'  => 'required|string|min:6|max:100',
        ]);

        $client      = Client::findOrFail($validated['client_id']);
        $companyName = $client->company_name ?? $client->legal_name ?? 'Client';
        $adminName   = $validated['name'] ?? $companyName;
        $email       = $validated['email'];

        $portalUser = User::updateOrCreate(
            ['email' => $email],
            [
                'name'     => $adminName,
                'password' => Hash::make($validated['password']),
                'role'     => 'client_admin',
                'status'   => 'Active',
            ]
        );

        DB::table('client_contacts')->upsert(
            [['client_id' => $client->id, 'email' => $email, 'name' => $adminName, 'created_at' => now(), 'updated_at' => now()]],
            ['email'],
            ['client_id', 'name', 'updated_at']
        );

        $client->portal_user_id    = $portalUser->id;
        $client->portal_enabled    = true;
        $client->portal_invited_at = now();
        $client->save();

        DB::table('ip_notifications')->insert([
            'user_id'     => $request->user()->id,
            'type'        => 'portal_invite',
            'title'       => 'Client portal created',
            'description' => "Created by {$request->user()->name} for {$companyName} ({$email})",
            'meta'        => json_encode(['client_id' => $client->id, 'email' => $email]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return response()->json([
            'ok'             => true,
            'email'          => $email,
            'portal_user_id' => $portalUser->id,
        ], 201);
    }

    /** List all portal users linked to a client. */
    public function clientUsers(Request $request, $id)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $client = Client::findOrFail($id);

        $contactEmails = DB::table('client_contacts')
            ->where('client_id', $client->id)
            ->pluck('email')
            ->filter()
            ->values();

        if ($contactEmails->isEmpty() && ! $client->portal_user_id) {
            return response()->json([]);
        }

        $users = User::query()
            ->where(function ($q) use ($contactEmails, $client) {
                if ($contactEmails->isNotEmpty()) {
                    $q->whereIn('email', $contactEmails);
                }
                if ($client->portal_user_id) {
                    $method = $contactEmails->isNotEmpty() ? 'orWhere' : 'where';
                    $q->{$method}('id', $client->portal_user_id);
                }
            })
            ->whereIn('role', User::CLIENT_ROLES)
            ->distinct()
            ->get(['id', 'name', 'email', 'role', 'status', 'created_at'])
            ->sortByDesc(fn ($u) => $u->id === (int) $client->portal_user_id)
            ->values()
            ->map(fn ($u) => [
                'id'         => $u->id,
                'name'       => $u->name,
                'email'      => $u->email,
                'role'       => $u->role,
                'is_primary' => $u->id === (int) $client->portal_user_id,
                'status'     => $u->status,
                'created_at' => $u->created_at?->toDateTimeString(),
            ]);

        return response()->json($users->values());
    }

    /** Add a new portal user (role=client) to a client. */
    public function addClientUser(Request $request, $id)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:6|max:100',
        ]);

        $client = Client::findOrFail($id);

        $newUser = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role'     => 'client',
            'status'   => 'Active',
        ]);

        DB::table('client_contacts')->upsert(
            [[
                'client_id'  => $client->id,
                'email'      => $validated['email'],
                'name'       => $validated['name'],
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['email'],
            ['client_id', 'name', 'updated_at']
        );

        return response()->json([
            'ok'   => true,
            'user' => [
                'id'         => $newUser->id,
                'name'       => $newUser->name,
                'email'      => $newUser->email,
                'role'       => $newUser->role,
                'is_primary' => false,
                'status'     => $newUser->status,
                'created_at' => $newUser->created_at->toDateTimeString(),
            ],
        ], 201);
    }

    /** Remove a non-admin portal user from a client. */
    public function removeClientUser(Request $request, $clientId, $userId)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $client = Client::findOrFail($clientId);
        $target = User::findOrFail($userId);

        if ((int) $client->portal_user_id === (int) $userId) {
            return response()->json(['message' => 'Cannot remove the primary portal admin. Disable the portal instead.'], 422);
        }

        $isLinked = DB::table('client_contacts')
            ->where('client_id', $client->id)
            ->where('email', $target->email)
            ->exists();

        if (! $isLinked) {
            return response()->json(['message' => 'This user is not linked to this client.'], 422);
        }

        $target->tokens()->delete();
        DB::table('sessions')->where('user_id', $target->id)->delete();
        DB::table('client_contacts')
            ->where('client_id', $client->id)
            ->where('email', $target->email)
            ->delete();
        $target->delete();

        return response()->json(['ok' => true]);
    }

    /** Reset password for any individual portal user of a client. */
    public function resetUserPassword(Request $request, $clientId, $userId)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $request->validate(['password' => 'required|string|min:6']);

        $client = Client::findOrFail($clientId);
        $user   = User::findOrFail($userId);

        $isLinked = DB::table('client_contacts')
                ->where('client_id', $client->id)
                ->where('email', $user->email)
                ->exists()
            || (int) $client->portal_user_id === (int) $userId;

        if (! $isLinked) {
            return response()->json(['message' => 'This user is not linked to this client.'], 422);
        }

        $user->password = Hash::make($request->input('password'));
        $user->save();
        $user->tokens()->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Bulk action on portal clients.
     * action: 'enable' | 'disable' | 'delete'
     * ids: client IDs
     */
    public function bulk(Request $request)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $validated = $request->validate([
            'action' => 'required|in:enable,disable,delete',
            'ids'    => 'required|array|min:1',
            'ids.*'  => 'integer|exists:clients,id',
        ]);

        $ids    = $validated['ids'];
        $action = $validated['action'];

        if ($action === 'enable') {
            Client::whereIn('id', $ids)->update([
                'portal_enabled'    => true,
                'portal_invited_at' => now(),
            ]);
        } elseif ($action === 'disable') {
            Client::whereIn('id', $ids)->update(['portal_enabled' => false]);
            $this->revokePortalAccess($ids);
        } elseif ($action === 'delete') {
            $userIds = $this->portalUserIdsForClients($ids);
            if ($userIds !== []) {
                $this->revokePortalAccess($ids);
                User::whereIn('id', $userIds)->delete();
            }

            // Strip portal data from client records
            Client::whereIn('id', $ids)->update([
                'portal_enabled'    => false,
                'portal_invited_at' => null,
                'portal_user_id'    => null,
            ]);
        }

        return response()->json(['ok' => true, 'affected' => count($ids)]);
    }

    /** Reset password for a portal user directly by client ID. */
    public function resetPassword(Request $request, $clientId)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $request->validate(['password' => 'required|string|min:6']);

        $client = Client::findOrFail($clientId);

        if (! $client->portal_user_id) {
            return response()->json(['message' => 'No portal user linked to this client. Create the portal first.'], 422);
        }

        $user = User::findOrFail($client->portal_user_id);
        $user->password = Hash::make($request->input('password'));
        $user->save();
        $user->tokens()->delete();

        return response()->json(['ok' => true]);
    }

    public function recentActivity(Request $request)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $logs = DB::table('audit_logs')
            ->join('users', 'audit_logs.user_id', '=', 'users.id')
            ->whereIn('audit_logs.action', [
                'portal_invite_sent', 'portal_enabled', 'portal_disabled',
                'portal_password_reset', 'create', 'update',
            ])
            ->where(function ($q) {
                $q->where('audit_logs.subject_type', 'Client')
                  ->orWhereIn('audit_logs.action', ['portal_invite_sent', 'portal_enabled', 'portal_disabled', 'portal_password_reset']);
            })
            ->orderByDesc('audit_logs.created_at')
            ->limit(15)
            ->select('audit_logs.id', 'audit_logs.action', 'audit_logs.subject_type', 'audit_logs.subject_id', 'audit_logs.metadata', 'audit_logs.created_at', 'users.name as user_name')
            ->get()
            ->map(fn ($r) => [
                'id'           => $r->id,
                'action'       => $r->action,
                'subject_type' => $r->subject_type,
                'subject_id'   => $r->subject_id,
                'user_name'    => $r->user_name,
                'metadata'     => is_string($r->metadata) ? json_decode($r->metadata, true) : $r->metadata,
                'created_at'   => $r->created_at,
            ]);

        return response()->json($logs);
    }
}
