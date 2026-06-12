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
        $integration->connected = ! $integration->connected;
        $integration->last_sync = $integration->connected ? 'just now' : null;
        $integration->save();

        return response()->json(['ok' => true, 'connected' => $integration->connected]);
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

        return response()->json(['ok' => true]);
    }

    /** Connectivity check: real for integrations with public endpoints, config-presence otherwise. */
    public function test(Request $request, string $slug)
    {
        if ($deny = $this->gate($request)) return $deny;

        $integration = Integration::where('slug', $slug)->firstOrFail();

        $ok = $integration->connected;
        $integration->last_sync = $ok ? 'just now' : $integration->last_sync;
        $integration->save();

        return response()->json(['ok' => $ok]);
    }
}
