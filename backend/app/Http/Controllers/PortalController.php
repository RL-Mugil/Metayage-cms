<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

    public function clients(Request $request)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        return response()->json(
            Client::orderBy('company_name')->limit(500)->get()->map(fn ($c) => [
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
            'email'     => 'required|email|max:255',
        ]);

        $client = Client::findOrFail($validated['client_id']);
        $name   = $client->company_name ?? $client->legal_name ?? 'Client';

        // Generate a readable temp password
        $tempPassword = 'Portal@' . rand(1000, 9999);

        // Create or update the portal user
        $portalUser = User::updateOrCreate(
            ['email' => $validated['email']],
            [
                'name'     => $name,
                'password' => Hash::make($tempPassword),
                'role'     => 'client',
                'status'   => 'Active',
            ]
        );

        // Link user to client and enable portal
        $client->portal_user_id  = $portalUser->id;
        $client->portal_enabled  = true;
        $client->portal_invited_at = now();
        $client->save();

        DB::table('ip_notifications')->insert([
            'user_id'     => $request->user()->id,
            'type'        => 'portal_invite',
            'title'       => 'Client portal created',
            'description' => "Portal account created for {$name} ({$validated['email']})",
            'meta'        => json_encode(['client_id' => $client->id, 'email' => $validated['email']]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return response()->json([
            'ok'           => true,
            'email'        => $validated['email'],
            'password'     => $tempPassword,
            'portal_user_id' => $portalUser->id,
        ], 201);
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
