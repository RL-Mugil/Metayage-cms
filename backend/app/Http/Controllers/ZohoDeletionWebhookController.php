<?php

namespace App\Http\Controllers;

use App\Http\Resources\ZohoSyncResource;
use App\Models\Integration;
use App\Services\ZohoBooksService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ZohoDeletionWebhookController extends Controller
{
    public function receive(Request $request, ZohoBooksService $zoho)
    {
        // The contract is raw JSON with no query parameters. Zoho signs that raw
        // JSON with HMAC-SHA256 and base64 encoding, using its secret token.
        abort_unless($request->isJson() && $request->query() === [] && strlen($request->getContent()) <= 8192, 400);
        $secret = config('zoho.webhook_secret');
        abort_unless(is_string($secret) && strlen($secret) >= 12, 503, 'Deletion webhook is not configured.');
        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));
        abort_unless(hash_equals($expected, $request->header('X-Zoho-Webhook-Signature', '')), 401);
        $integration = Integration::where('slug', 'zoho')->where('connected', true)->first();
        abort_unless($integration && $zoho->isConfigured(), 503);
        $input = $request->validate([
            'organization_id' => ['required', 'string', 'regex:/^[0-9]{1,50}$/'],
            'resource' => 'required|in:invoices,estimates', 'event' => 'required|in:deleted',
            'remote_id' => ['required', 'string', 'regex:/^[0-9]{1,80}$/'],
        ]);
        abort_unless($input['organization_id'] === (string) ($integration->config['organization_id'] ?? ''), 403);
        $scope = $zoho->scope();
        $key = hash('sha256', $scope . ':' . $input['resource'] . ':' . $input['remote_id'] . ':deleted');
        // Acknowledge only after durable insertion. Duplicate delivery is harmless.
        DB::transaction(function () use ($key, $scope, $input) {
            $inserted = DB::table('zoho_webhook_events')->insertOrIgnore(['scope' => $scope, 'resource' => $input['resource'],
                'remote_id' => $input['remote_id'], 'event_key' => $key, 'received_at' => now()]);
            if ($inserted) {
                \App\Models\AuditLog::create(['user_id' => null, 'action' => 'zoho_deletion_received', 'subject_type' => 'ZohoWebhook', 'subject_id' => null,
                    'metadata' => ['scope' => $scope, 'resource' => $input['resource'], 'remote_id' => $input['remote_id']],
                    'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);
            }
        });
        return (new ZohoSyncResource(['ok' => true, 'message' => 'Deletion event saved.']))->response()->setStatusCode(202);
    }
}
