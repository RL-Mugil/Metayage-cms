<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PortalController extends Controller
{
    private function denyClients(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if ($request->user()->role === 'client') {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }

    public function clients(Request $request)
    {
        if ($deny = $this->denyClients($request)) return $deny;

        return response()->json(
            Client::orderBy('company_name')->limit(500)->get()->map(fn ($c) => [
                'id' => $c->id,
                'client_code' => $c->client_code,
                'company_name' => $c->company_name ?? $c->legal_name,
                'portal_enabled' => (bool) $c->portal_enabled,
                'portal_invited_at' => $c->portal_invited_at?->toDateTimeString(),
            ])
        );
    }

    public function toggle(Request $request, $id)
    {
        if ($deny = $this->denyClients($request)) return $deny;

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
        if ($deny = $this->denyClients($request)) return $deny;

        $count = Client::where('portal_enabled', false)->update(['portal_invited_at' => now()]);

        DB::table('ip_notifications')->insert([
            'user_id' => $request->user()->id,
            'type' => 'portal_invite',
            'title' => 'Portal invitations sent',
            'description' => "Invitations recorded for {$count} inactive clients",
            'meta' => json_encode(['count' => $count]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'invited' => $count]);
    }

    public function create(Request $request)
    {
        if ($deny = $this->denyClients($request)) return $deny;

        $validated = $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'email' => 'required|email|max:255',
        ]);

        $client = Client::findOrFail($validated['client_id']);
        $client->portal_enabled = true;
        $client->portal_invited_at = now();
        $client->save();

        DB::table('ip_notifications')->insert([
            'user_id' => $request->user()->id,
            'type' => 'portal_invite',
            'title' => 'Client portal created',
            'description' => "Portal enabled for {$client->company_name}; credentials noted for {$validated['email']}",
            'meta' => json_encode($validated),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true], 201);
    }
}
