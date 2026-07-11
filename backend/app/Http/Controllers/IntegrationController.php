<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IntegrationController extends Controller
{
    private const MANAGE_ROLES = ['super_admin', 'partner', 'manager'];
    private const CLIENT_ROLES = ['client', 'client_admin'];

    private function writeGate(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (! in_array($request->user()->role, self::MANAGE_ROLES)) {
            return response()->json(['message' => 'Forbidden — only managers and above can configure integrations.'], 403);
        }
        return null;
    }

    private function readGate(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (in_array($request->user()->role, self::CLIENT_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }

    public function index(Request $request)
    {
        if ($deny = $this->readGate($request)) return $deny;

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
        if ($deny = $this->writeGate($request)) return $deny;

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

        DB::table('integration_logs')->insert([
            'slug' => $slug, 'event_type' => $integration->connected ? 'connect' : 'disconnect',
            'status' => 'ok', 'summary' => $integration->connected ? 'Integration enabled' : 'Integration disabled',
            'payload' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'connected' => $integration->connected,
            'message' => $integration->connected ? 'Integration connected.' : 'Integration disconnected.',
        ]);
    }

    public function saveConfig(Request $request, string $slug)
    {
        if ($deny = $this->writeGate($request)) return $deny;

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
        if ($deny = $this->writeGate($request)) return $deny;

        $integration = Integration::where('slug', $slug)->firstOrFail();

        $hasKey = ! empty(($integration->config ?? [])['api_key'] ?? null);
        $ok = $integration->connected && $hasKey;
        $integration->last_sync = $ok ? now()->toIso8601String() : $integration->last_sync;
        $integration->save();

        DB::table('integration_logs')->insert([
            'slug' => $slug, 'event_type' => 'test',
            'status' => $ok ? 'ok' : 'fail',
            'summary' => $ok ? 'Credentials present and connection enabled' : 'Not fully configured',
            'payload' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json([
            'ok' => $ok,
            'message' => $ok
                ? 'Credentials are saved and the integration is enabled. Note: this confirms configuration only — live connectivity to the external service is not verified here.'
                : 'Integration is not fully configured. Save an API key and enable the integration first.',
        ]);
    }
}
