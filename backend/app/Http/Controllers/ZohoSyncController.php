<?php

namespace App\Http\Controllers;

use App\Models\ZohoInvoice;
use App\Http\Resources\ZohoSyncResource;
use App\Models\ZohoSnapshot;
use App\Services\ZohoBooksService;
use App\Services\ZohoMirrorSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class ZohoSyncController extends Controller
{
    public function status(ZohoBooksService $zoho, ZohoMirrorSync $sync): JsonResponse
    {
        $this->authorize('viewSyncStatus', ZohoInvoice::class);
        return (new ZohoSyncResource([
            'budget' => app(\App\Services\ZohoApiBudget::class)->status($zoho->scope()),
            'pending' => $sync->pending()->count(),
            'evening' => \Illuminate\Support\Facades\DB::table('zoho_sync_progress')->where('scope', $zoho->scope())->where('resource', '__cycle')->first(['evening_eligible_date', 'pause_reason', 'completed_at']),
            'deletions' => [
                'mode' => 'manual',
                'configured' => strlen((string) config('zoho.webhook_secret')) >= 12,
                'pending' => \Illuminate\Support\Facades\DB::table('zoho_webhook_events')->where('scope', $zoho->scope())->whereNull('processed_at')->count(),
                'failed' => \Illuminate\Support\Facades\DB::table('zoho_webhook_events')->where('scope', $zoho->scope())->whereNull('processed_at')->where('attempts', '>=', 5)->count(),
                'last_received_at' => \Illuminate\Support\Facades\DB::table('zoho_webhook_events')->where('scope', $zoho->scope())->max('received_at'),
            ],
            'last_run' => \Illuminate\Support\Facades\DB::table('integration_logs')
                ->where('slug', 'zoho')->where('event_type', 'sync')->orderByDesc('id')
                ->first(['status', 'summary', 'created_at']),
        ]))->response();
    }

    public function refresh(ZohoInvoice $record, ZohoMirrorSync $sync): JsonResponse
    {
        $this->authorize('refresh', $record);
        $result = $sync->run($record);
        $response = (new ZohoSyncResource($result))->response()->setStatusCode(match ($result['status']) {
            'ok' => 200, 'paused' => 429, 'busy' => 409, 'unconfigured' => 422, default => 502,
        });
        if (($result['retry_after'] ?? 0) > 0) {
            $response->headers->set('Retry-After', (string) $result['retry_after']);
        }
        return $response;
    }

    public function pdf(ZohoInvoice $record, ZohoBooksService $zoho)
    {
        $this->authorize('refresh', $record);
        $snapshot = ZohoSnapshot::where('scope', $zoho->scope())
            ->where('resource', $record->zoho_type === 'invoice' ? 'invoices' : 'estimates')
            ->where('remote_id', $record->zoho_id)->first();
        abort_unless($snapshot?->pdf_path && $snapshot->pdf_fingerprint === $snapshot->fingerprint, 404, 'PDF is awaiting sync.');
        abort_unless(Storage::disk('local')->exists($snapshot->pdf_path), 404, 'PDF is awaiting sync.');
        return response(decrypt(Storage::disk('local')->get($snapshot->pdf_path)), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="zoho-' . $record->id . '.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
