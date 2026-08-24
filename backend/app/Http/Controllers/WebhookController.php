<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    /**
     * Receive an inbound webhook from an external integration. Public endpoint
     * (no auth) — anyone can POST here, so an integration that has configured a
     * shared secret (Integration.config['webhook_secret']) must present a valid
     * HMAC-SHA256 signature or the payload is rejected before it's logged.
     * Integrations with no secret configured are logged as before — no forced
     * migration for existing/legacy connections that predate this check.
     */
    public function receive(Request $request, string $slug)
    {
        $integration = Integration::where('slug', $slug)->where('connected', true)->first();
        if (!$integration) {
            return response()->json(['ok' => false, 'message' => 'Integration not found or not connected.'], 404);
        }

        $secret = $integration->config['webhook_secret'] ?? null;
        if ($secret) {
            $signature = $request->header('X-Webhook-Signature', '');
            $expected  = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);
            if (! $signature || ! hash_equals($expected, $signature)) {
                DB::table('integration_logs')->insert([
                    'slug' => $slug, 'event_type' => 'webhook', 'status' => 'error',
                    'summary' => 'Rejected: invalid or missing signature',
                    'payload' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
                return response()->json(['ok' => false, 'message' => 'Invalid signature.'], 401);
            }
        }

        DB::table('integration_logs')->insert([
            'slug'       => $slug,
            'event_type' => 'webhook',
            'status'     => 'ok',
            'summary'    => 'Inbound webhook received',
            'payload'    => json_encode($request->all()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /** List recent logs for a given integration (admin only). */
    public function logs(Request $request, string $slug)
    {
        if (!in_array($request->user()->role, ['super_admin', 'partner', 'manager'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $logs = DB::table('integration_logs')
            ->where('slug', $slug)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'id'         => $r->id,
                'event_type' => $r->event_type,
                'status'     => $r->status,
                'summary'    => $r->summary,
                'created_at' => $r->created_at,
            ]);

        return response()->json($logs);
    }
}
