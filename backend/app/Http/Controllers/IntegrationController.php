<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use App\Services\ZohoBooksService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IntegrationController extends Controller
{
    /** Roles that may change integration config (toggle/test/configure). Director is read-only. */
    private const WRITE_ROLES = ['super_admin', 'manager', 'galvanizer'];
    /** Roles that may view integrations list (read-only). */
    private const READ_ROLES  = ['super_admin', 'partner', 'manager', 'hr', 'associate', 'paralegal', 'finance', 'galvanizer'];
    private const CLIENT_ROLES = ['client', 'client_admin'];

    /** AI Assistant keys and the Claude connector switch: System Admin only. */
    private const ADMIN_ONLY = ['anthropic', 'groq', 'claude_mcp'];

    /** Cards that are a plain on/off switch with no key. */
    private const SWITCH_ONLY = ['claude_mcp'];

    private function writeGate(Request $request, ?string $slug = null): ?\Illuminate\Http\JsonResponse
    {
        if (in_array($slug, self::ADMIN_ONLY, true) && $request->user()->role !== 'super_admin') {
            return response()->json(['message' => 'Forbidden — only a System Admin can configure the AI integrations.'], 403);
        }
        if (! in_array($request->user()->role, self::WRITE_ROLES)) {
            return response()->json(['message' => 'Forbidden — only system admins and patent attorneys can configure integrations.'], 403);
        }
        return null;
    }

    private function readGate(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (! in_array($request->user()->role, self::READ_ROLES)) {
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
                // Not secret: the AI model name, and whether the card is a switch with no key.
                'model' => in_array($i->slug, ['anthropic', 'groq'], true) ? (($i->config ?? [])['model'] ?? null) : null,
                'switchOnly' => in_array($i->slug, self::SWITCH_ONLY, true),
            ])
        );
    }

    public function toggle(Request $request, string $slug)
    {
        if ($deny = $this->writeGate($request, $slug)) return $deny;

        $integration = Integration::where('slug', $slug)->firstOrFail();
        $hasKey = ! empty(($integration->config ?? [])['api_key'] ?? null) || in_array($slug, self::SWITCH_ONLY, true);

        if (! $integration->connected && ! $hasKey) {
            return response()->json([
                'message' => 'Save the API key or credentials before connecting this integration.',
            ], 422);
        }

        $integration->connected = ! $integration->connected;
        $integration->last_sync = $integration->connected ? now()->toIso8601String() : null;
        $integration->save();

        if ($slug === 'claude_mcp') {
            \App\Models\AuditLog::create([
                'user_id' => $request->user()->id, 'action' => $integration->connected ? 'claude_connector_enabled' : 'claude_connector_disabled',
                'subject_type' => 'Integration', 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
        }

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
        if ($deny = $this->writeGate($request, $slug)) return $deny;
        if (in_array($slug, self::SWITCH_ONLY, true)) {
            return response()->json(['message' => 'This integration has no key; use the switch.'], 422);
        }

        $integration = Integration::where('slug', $slug)->firstOrFail();
        $config = $integration->config ?? [];

        if ($slug === 'zoho') {
            $validated = $request->validate([
                'client_id'       => 'required|string|max:255',
                'client_secret'   => 'required|string|max:255',
                'refresh_token'   => 'required|string|max:500',
                'organization_id' => 'required|string|max:50',
                'region'          => 'nullable|string|max:5',
            ]);

            $config['client_id']       = $validated['client_id'];
            $config['client_secret']   = encrypt($validated['client_secret']);
            $config['refresh_token']   = encrypt($validated['refresh_token']);
            $config['organization_id'] = $validated['organization_id'];
            $config['region']          = $validated['region'] ?? 'in';
            // Kept only so the generic `hasKey` flag on the integrations list still works.
            $config['api_key'] = $config['refresh_token'];
        } elseif ($slug === 'api_setu' || $slug === 'entitylocker') {
            // PAN Verification and EntityLocker share the same shape: a simple
            // REST endpoint. base_url/verify_path are kept configurable rather
            // than hardcoded — the exact URL is issued per-consumer once API
            // Setu approves the registration, and isn't publicly documented.
            $validated = $request->validate([
                'client_id'     => 'required|string|max:255',
                'client_secret' => 'required|string|max:500',
                'base_url'      => 'required|url|max:500',
                'verify_path'   => 'required|string|max:255',
            ]);

            $config['client_id']     = $validated['client_id'];
            $config['client_secret'] = encrypt($validated['client_secret']);
            $config['base_url']      = rtrim($validated['base_url'], '/');
            $config['verify_path']   = '/' . ltrim($validated['verify_path'], '/');
            $config['api_key']       = $config['client_secret'];
        } elseif ($slug === 'digilocker') {
            // Three-legged OAuth2 consent flow — every endpoint is configurable
            // for the same reason as above (per-consumer, approval-issued URLs).
            $validated = $request->validate([
                'client_id'          => 'required|string|max:255',
                'client_secret'      => 'required|string|max:500',
                'authorize_url'      => 'required|url|max:500',
                'token_url'          => 'required|url|max:500',
                'document_pull_url'  => 'required|url|max:500',
                'redirect_uri'       => 'required|url|max:500',
            ]);

            $config['client_id']         = $validated['client_id'];
            $config['client_secret']     = encrypt($validated['client_secret']);
            $config['authorize_url']     = $validated['authorize_url'];
            $config['token_url']         = $validated['token_url'];
            $config['document_pull_url'] = $validated['document_pull_url'];
            $config['redirect_uri']      = $validated['redirect_uri'];
            $config['api_key']           = $config['client_secret'];
        } elseif ($slug === 'anthropic' || $slug === 'groq') {
            // AI Assistant: key encrypted at rest, optional model name. Saving a key turns the card on.
            $validated = $request->validate([
                'api_key' => 'nullable|string|max:500',
                'model' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._\/:\-]+$/'],
            ]);
            if (! empty($validated['api_key'])) {
                $config['api_key'] = encrypt(trim($validated['api_key']));
                $integration->connected = true;
            } elseif (empty($config['api_key'])) {
                return response()->json(['message' => 'Enter the API key.'], 422);
            }
            $config['model'] = $validated['model'] ?? null;
            \App\Models\AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'ai_key_saved', 'subject_type' => 'Integration',
                'metadata' => ['slug' => $slug, 'key_changed' => ! empty($validated['api_key']), 'model' => $config['model']],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
        } else {
            $validated = $request->validate(['api_key' => 'required|string|max:500']);
            $config['api_key'] = encrypt($validated['api_key']);
        }

        $integration->config = $config;
        $integration->save();

        return response()->json(['ok' => true, 'message' => 'Integration credentials saved.']);
    }

    /** Connectivity check: real (live API ping) for Zoho, config-presence otherwise. */
    public function test(Request $request, string $slug)
    {
        if ($deny = $this->writeGate($request, $slug)) return $deny;

        $integration = Integration::where('slug', $slug)->firstOrFail();

        if ($slug === 'anthropic' || $slug === 'groq') {
            [$ok, $message] = $this->pingAi($slug === 'anthropic' ? 'claude' : 'groq');
        } elseif ($slug === 'claude_mcp') {
            $ok = (bool) $integration->connected;
            $message = $ok ? 'The Claude connector is on. Staff can add '.rtrim((string) config('app.url'), '/').'/mcp in claude.ai or Claude Desktop.' : 'The Claude connector is off.';
        } elseif ($slug === 'zoho') {
            try {
                $ok = app(ZohoBooksService::class)->pingOrganization();
                $message = $ok
                    ? 'Connected to Zoho Books successfully.'
                    : 'Zoho Books responded but the organization could not be verified.';
            } catch (\Throwable $e) {
                $ok = false;
                $message = 'Zoho Books connection failed: ' . $e->getMessage();
            }
        } else {
            $hasKey = ! empty(($integration->config ?? [])['api_key'] ?? null);
            $ok = $integration->connected && $hasKey;
            $message = $ok
                ? 'Credentials are saved and the integration is enabled. Note: this confirms configuration only — live connectivity to the external service is not verified here.'
                : 'Integration is not fully configured. Save an API key and enable the integration first.';
        }

        $integration->last_sync = $ok ? now()->toIso8601String() : $integration->last_sync;
        $integration->save();

        DB::table('integration_logs')->insert([
            'slug' => $slug, 'event_type' => 'test',
            'status' => $ok ? 'ok' : 'fail',
            'summary' => mb_substr($message, 0, 250),
            'payload' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['ok' => $ok, 'message' => $message]);
    }

    /** One tiny live call to the AI provider (lists models); never echoes the key. @return array{0: bool, 1: string} */
    private function pingAi(string $provider): array
    {
        ['key' => $key, 'model' => $model] = \App\Ai\Keys::for($provider);
        if (! $key) {
            return [false, 'No API key saved (or the card is switched off).'];
        }
        try {
            $res = $provider === 'claude'
                ? \Illuminate\Support\Facades\Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(15)->get('https://api.anthropic.com/v1/models', ['limit' => 1])
                : \Illuminate\Support\Facades\Http::withToken($key)->timeout(15)->get('https://api.groq.com/openai/v1/models');
        } catch (\Throwable $e) {
            return [false, 'Could not reach the provider: '.mb_substr($e->getMessage(), 0, 150)];
        }
        if ($res->successful()) {
            return [true, ($provider === 'claude' ? 'Claude' : 'Groq')." API key works. Model in use: {$model}."];
        }

        return [false, ($provider === 'claude' ? 'Claude' : 'Groq').' rejected the key (HTTP '.$res->status().'). Check the key and try again.'];
    }
}
