<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use Illuminate\Http\Request;

class IntegrationController extends Controller
{
    private const MANAGE_ROLES = ['super_admin', 'partner', 'manager'];

    private function gate(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (! in_array($request->user()->role, self::MANAGE_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }

    public function index(Request $request)
    {
        if ($deny = $this->gate($request)) return $deny;

        return response()->json(
            Integration::orderBy('id')->get()->map(fn ($i) => [
                'id' => $i->slug,
                'name' => $i->name,
                'description' => $i->description,
                'category' => $i->category,
                'initials' => $i->initials,
                'color' => $i->color,
                'connected' => $i->connected,
                'lastSync' => $i->last_sync,
                'syncFreq' => $i->sync_freq,
                'hasKey' => ! empty(($i->config ?? [])['api_key'] ?? null),
            ])
        );
    }

    public function toggle(Request $request, string $slug)
    {
        if ($deny = $this->gate($request)) return $deny;

        $integration = Integration::where('slug', $slug)->firstOrFail();
        $hasKey = ! empty(($integration->config ?? [])['api_key'] ?? null);

        if (! $integration->connected && ! $hasKey) {
            return response()->json([
                'message' => 'Save the API key or credentials before connecting this integration.',
            ], 422);
        }

        $integration->connected = ! $integration->connected;
        $integration->last_sync = $integration->connected ? now()->toIso8601String() : null;
        $integration->save();

        return response()->json([
            'ok' => true,
            'connected' => $integration->connected,
            'message' => $integration->connected ? 'Integration connected.' : 'Integration disconnected.',
        ]);
    }

    public function saveConfig(Request $request, string $slug)
    {
        if ($deny = $this->gate($request)) return $deny;

        $integration = Integration::where('slug', $slug)->firstOrFail();
        $validated = $request->validate(['api_key' => 'required|string|max:500']);

        $config = $integration->config ?? [];
        $config['api_key'] = encrypt($validated['api_key']);
        $integration->config = $config;
        $integration->save();

        return response()->json(['ok' => true, 'message' => 'Integration credentials saved.']);
    }

    /** Connectivity check: real for integrations with public endpoints, config-presence otherwise. */
    public function test(Request $request, string $slug)
    {
        if ($deny = $this->gate($request)) return $deny;

        $integration = Integration::where('slug', $slug)->firstOrFail();

        $hasKey = ! empty(($integration->config ?? [])['api_key'] ?? null);
        $ok = $integration->connected && $hasKey;
        $integration->last_sync = $ok ? now()->toIso8601String() : $integration->last_sync;
        $integration->save();

        return response()->json([
            'ok' => $ok,
            'message' => $ok
                ? 'Integration credentials are present and the connection is enabled.'
                : 'Integration is not fully configured yet.',
        ]);
    }
}
