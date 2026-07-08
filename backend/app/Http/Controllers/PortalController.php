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
     * Create or reset a client portal account.
     * Returns the generated password in the response (since SMTP is not configured).
     */
    public function create(Request $request)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $validated = $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'emails'    => 'required|array|min:1',
            'emails.*'  => 'required|email|max:255',
            'password'  => 'required|string|min:6|max:100',
        ]);

        $client  = Client::findOrFail($validated['client_id']);
        $name    = $client->company_name ?? $client->legal_name ?? 'Client';
        $results = [];
        $primaryUserId = null;

        foreach ($validated['emails'] as $i => $email) {
            // Creator sets the password manually; clients can change it later
            // in Settings → Security.
            $portalUser = User::updateOrCreate(
                ['email' => $email],
                [
                    'name'     => $name,
                    'password' => Hash::make($validated['password']),
                    // First email is the primary contact → client_admin
                    // (manages their company's portal users, approves/rejects).
                    'role'     => $i === 0 ? 'client_admin' : 'client',
                    'status'   => 'Active',
                ]
            );

            // Ensure a client_contact record exists so RBAC data scoping works
            DB::table('client_contacts')->upsert(
                [['client_id' => $client->id, 'email' => $email, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]],
                ['email'],
                ['client_id', 'name', 'updated_at']
            );

            if ($primaryUserId === null) {
                $primaryUserId = $portalUser->id;
            }

            // Credential emails disabled for now — the creator shares the
            // password directly. Re-enable by uncommenting.
            // try {
            //     Mail::to($email)->send(new PortalInviteMail(
            //         clientName: $name,
            //         email:      $email,
            //         password:   $validated['password'],
            //         loginUrl:   config('app.url') . '/login',
            //     ));
            // } catch (\Throwable) {}

            $results[] = ['email' => $email];
        }

        $client->portal_user_id   = $primaryUserId;
        $client->portal_enabled   = true;
        $client->portal_invited_at = now();
        $client->save();

        $emailList = implode(', ', array_column($results, 'email'));
        DB::table('ip_notifications')->insert([
            'user_id'     => $request->user()->id,
            'type'        => 'portal_invite',
            'title'       => 'Client portal created',
            'description' => "Created by {$request->user()->name} for {$name} ({$emailList})",
            'meta'        => json_encode(['client_id' => $client->id, 'emails' => array_column($results, 'email')]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return response()->json([
            'ok'             => true,
            'results'        => $results,
            'portal_user_id' => $primaryUserId,
        ], 201);
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
}
