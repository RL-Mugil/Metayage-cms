<?php

namespace App\Http\Controllers;

use App\Exceptions\ZohoBudgetExhausted;
use App\Http\Requests\SendZohoQuoteRequest;
use App\Http\Resources\ZohoSyncResource;
use App\Models\ZohoQuoteExport;
use App\Services\ZohoBooksService;
use App\Services\ZohoQuoteDraft;
use App\Services\ZohoQuoteWriter;
use App\Services\ZohoMirrorSync;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ZohoQuoteController extends Controller
{
    public function preview(Request $request, string $kind, int $id, ZohoBooksService $zoho, ZohoQuoteDraft $drafts): ZohoSyncResource
    {
        $this->authorize('create', ZohoQuoteExport::class);
        $input = $request->validate(['search' => 'nullable|string|max:100', 'customer_id' => ['nullable', 'string', 'regex:/^[0-9]{1,80}$/']]);
        $quote = $drafts->source($kind, $id);
        $scope = $zoho->scope();
        $export = ZohoQuoteExport::where('scope', $scope)->where('source_type', $kind)->where('source_id', $id)->first();
        $customerId = (string) ($input['customer_id'] ?? $quote->client?->zoho_contact_id ?? '');
        $preview = null;
        $error = $zoho->isConfigured() ? null : 'Zoho Books is not configured.';
        if ($customerId !== '' && $error === null) {
            try {
                $preview = $drafts->build($quote, $scope, $customerId);
            } catch (ValidationException $exception) {
                $error = $exception->validator->errors()->first();
            }
        }
        return new ZohoSyncResource(['state' => $export?->state ?? 'new', 'estimate_id' => $export?->estimate_id,
            'invoice_id' => $export?->invoice_id, 'conversion_state' => $export?->conversion_state ?? 'not_requested',
            'customer_id' => $customerId, 'contacts' => $drafts->contacts($scope, $input['search'] ?? ''),
            'preview' => $preview, 'error' => $error]);
    }

    public function send(SendZohoQuoteRequest $request, string $kind, int $id, ZohoQuoteWriter $writer)
    {
        $input = $request->validated();
        try {
            return new ZohoSyncResource($writer->send($kind, $id, $input['customer_id'], $input['version']));
        } catch (ZohoBudgetExhausted $exception) {
            return (new ZohoSyncResource(['message' => $exception->getMessage()]))->response()->setStatusCode(429)
                ->header('Retry-After', (string) $exception->retryAfter);
        }
    }

    public function deleteInZoho(Request $request, string $kind, int $id, ZohoQuoteWriter $writer): ZohoSyncResource
    {
        $this->authorize('create', ZohoQuoteExport::class);
        return new ZohoSyncResource($writer->deleteInZoho($kind, $id));
    }

    public function convertToInvoice(Request $request, string $kind, int $id, ZohoQuoteWriter $writer, ZohoMirrorSync $mirror): ZohoSyncResource
    {
        $this->authorize('create', ZohoQuoteExport::class);
        $result = $writer->convertToInvoice($kind, $id);
        if (! empty($result['invoice_id'])) {
            try {
                $mirror->syncRemote('invoices', (string) $result['invoice_id']);
            } catch (\Throwable $error) {
                report($error);
                $result['message'] .= ' The invoice was created in Zoho; portal mirroring is queued for the next poll.';
            }
        }
        return new ZohoSyncResource($result);
    }
}
