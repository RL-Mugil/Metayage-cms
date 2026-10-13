<?php

namespace App\Services;

use App\Exceptions\ZohoApiException;
use App\Exceptions\ZohoBudgetExhausted;
use App\Models\AuditLog;
use App\Models\ZohoQuoteExport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class ZohoQuoteWriter
{
    public function __construct(private ZohoBooksService $zoho, private ZohoQuoteDraft $drafts) {}

    public function send(string $kind, int $id, string $customerId, string $version): array
    {
        abort_unless($this->zoho->isConfigured(), 422, 'Zoho Books is not configured.');
        $scope = $this->zoho->scope();
        $lock = Cache::lock('zoho_quote:' . $scope . ':' . $kind . ':' . $id, 120);
        abort_unless($lock->get(), 409, 'This quotation is already being sent.');
        try {
            [$export, $dispatch] = DB::transaction(function () use ($scope, $kind, $id, $customerId, $version) {
                $quote = $this->drafts->source($kind, $id);
                // The source row serializes the first insert as well as repeated sends.
                $quote = $quote->newQuery()->whereKey($id)->lockForUpdate()->firstOrFail();
                $existing = ZohoQuoteExport::where(compact('scope'))->where('source_type', $kind)->where('source_id', $id)->lockForUpdate()->first();
                if ($existing && $existing->state !== 'rejected') {
                    return [$existing, false];
                }
                $draft = $this->drafts->build($quote, $scope, $customerId);
                abort_unless(hash_equals($draft['version'], $version), 409, 'The quotation or customer changed. Review the refreshed draft before sending.');
                $export = ZohoQuoteExport::updateOrCreate(['scope' => $scope, 'source_type' => $kind, 'source_id' => $id], [
                    'client_id' => $quote->client_id, 'project_id' => $quote->project_id,
                    'customer_id' => $customerId, 'reference' => $draft['payload']['reference_number'],
                    'state' => 'sending', 'payload' => json_encode($draft['payload'], JSON_THROW_ON_ERROR),
                ]);
                $this->audit($export, 'zoho_quote_send_started');
                return [$export, true];
            });
            if (! $dispatch) {
                abort_if($export->state === 'deleted', 409, 'This quote was deleted in Zoho. Automatic re-export is blocked.');
                abort_unless($export->state === 'confirmed', 409, 'The previous send is awaiting reconciliation. Do not send another copy; the nightly sync will check Zoho.');
                return $this->result($export);
            }
            try {
                $record = $this->zoho->createDraftEstimate(json_decode($export->payload, true, 512, JSON_THROW_ON_ERROR));
                if ((string) ($record['customer_id'] ?? '') !== $export->customer_id || ($record['reference_number'] ?? '') !== $export->reference) {
                    throw new \RuntimeException('Unexpected Zoho quotation identity.');
                }
                DB::transaction(function () use ($export, $record) {
                    $deleted = app(ZohoRecordLifecycle::class)->isDeleted($export->scope, 'estimates', (string) $record['estimate_id']);
                    $locked = ZohoQuoteExport::whereKey($export->id)->lockForUpdate()->firstOrFail();
                    $locked->update(['estimate_id' => (string) $record['estimate_id'], 'state' => $deleted ? 'deleted' : 'confirmed']);
                    $this->audit($locked, 'zoho_quote_created');
                });
                return $this->result($export->refresh());
            } catch (Throwable $error) {
                $rejected = $error instanceof ZohoBudgetExhausted || $error instanceof \App\Exceptions\ZohoWriteNotSent
                    || ($error instanceof ZohoApiException && in_array($error->status, [400, 401, 403, 404, 422], true));
                DB::transaction(function () use ($export, $rejected) {
                    $locked = ZohoQuoteExport::whereKey($export->id)->lockForUpdate()->firstOrFail();
                    if (! in_array($locked->state, ['confirmed', 'deleted'], true)) {
                        $locked->update(['state' => $rejected ? 'rejected' : 'uncertain']);
                        $this->audit($locked, 'zoho_quote_' . $locked->state);
                    }
                });
                if ($error instanceof ZohoBudgetExhausted) {
                    throw $error;
                }
                // Never log request bodies/tokens from HTTP exceptions.
                abort($rejected ? 422 : 409, $rejected
                    ? 'Zoho rejected the draft. Check the customer, OAuth estimates.CREATE permission and quote settings before trying again.'
                    : 'The send outcome is uncertain. Repeat sending is blocked; the nightly sync will check for the quote.');
            }
        } finally {
            $lock->release();
        }
    }

    /** Manually delete an already exported draft in Zoho; exactly one write call. */
    public function deleteInZoho(string $kind, int $id): array
    {
        abort_unless($this->zoho->isConfigured(), 422, 'Zoho Books is not configured.');
        $scope = $this->zoho->scope();
        $lock = Cache::lock('zoho_quote:' . $scope . ':' . $kind . ':' . $id, 120);
        abort_unless($lock->get(), 409, 'This quotation is being updated. Try again shortly.');
        try {
            $export = ZohoQuoteExport::where(compact('scope'))->where('source_type', $kind)->where('source_id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($export->estimate_id, 409, 'This quotation has no confirmed Zoho draft to delete.');
            if ($export->state === 'deleted') {
                return $this->result($export);
            }
            abort_unless($export->state === 'confirmed', 409, 'Only a confirmed Zoho draft can be deleted manually.');
            $this->zoho->deleteEstimate((string) $export->estimate_id);
            DB::transaction(function () use ($export) {
                $locked = ZohoQuoteExport::whereKey($export->id)->lockForUpdate()->firstOrFail();
                $locked->update(['state' => 'deleted']);
                app(ZohoRecordLifecycle::class)->delete($locked->scope, 'estimates', (string) $locked->estimate_id, 'manual_portal_delete');
                $this->audit($locked, 'zoho_quote_deleted_manually');
            });
            return $this->result($export->refresh());
        } finally {
            $lock->release();
        }
    }

    public function convertToInvoice(string $kind, int $id): array
    {
        abort_unless($this->zoho->isConfigured(), 422, 'Zoho Books is not configured.');
        $scope = $this->zoho->scope();
        $lock = Cache::lock('zoho_quote:' . $scope . ':' . $kind . ':' . $id, 120);
        abort_unless($lock->get(), 409, 'This quotation is already being updated.');
        try {
            $export = ZohoQuoteExport::where(compact('scope'))->where('source_type', $kind)->where('source_id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($export->state === 'confirmed' && $export->estimate_id, 409, 'Only a confirmed Zoho draft can be converted.');
            if ($export->invoice_id || $export->conversion_state === 'converted') {
                return $this->conversionResult($export);
            }
            abort_unless($export->conversion_state !== 'converting', 409, 'Invoice conversion is already in progress.');
            $export->update(['conversion_state' => 'converting']);
            $this->audit($export, 'zoho_invoice_conversion_started');
            try {
                $invoice = $this->zoho->createInvoiceFromEstimate((string) $export->estimate_id);
                $export->update(['invoice_id' => (string) $invoice['invoice_id'], 'conversion_state' => 'converted', 'converted_at' => now()]);
                $this->audit($export, 'zoho_invoice_converted');
                return $this->conversionResult($export->refresh());
            } catch (Throwable $error) {
                $export->update(['conversion_state' => 'uncertain']);
                $this->audit($export, 'zoho_invoice_conversion_uncertain');
                throw new \Symfony\Component\HttpKernel\Exception\HttpException(409, 'The conversion result is uncertain. Retry is blocked until the next Zoho reconciliation confirms whether an invoice exists.');
            }
        } finally {
            $lock->release();
        }
    }

    /** Called within the mirror transaction; no extra Books requests. */
    public function reconcile(string $scope, array $record, string $type): ?ZohoQuoteExport
    {
        $estimateId = trim((string) ($record['estimate_id'] ?? $record['invoiced_estimate_id'] ?? ''));
        $reference = trim((string) ($record['reference_number'] ?? ''));
        if ($estimateId !== '' && app(ZohoRecordLifecycle::class)->isDeleted($scope, 'estimates', $estimateId)) {
            return null;
        }
        if ($estimateId === '' && $reference === '') {
            return null;
        }
        $export = ZohoQuoteExport::where('scope', $scope)->where('customer_id', (string) ($record['customer_id'] ?? ''))
            ->where(function ($q) use ($estimateId, $reference) {
                $q->where('reference', $reference);
                if ($estimateId !== '') {
                    $q->orWhere('estimate_id', $estimateId);
                }
            })->lockForUpdate()->first();
        if ($export?->estimate_id && $estimateId !== '' && $export->estimate_id !== $estimateId) {
            return null;
        }
        if ($export && $type === 'quote' && $estimateId !== '' && (! $export->estimate_id || $export->estimate_id === $estimateId)) {
            if ($export->state !== 'confirmed') {
                $export->update(['state' => 'confirmed', 'estimate_id' => $estimateId]);
                $this->audit($export, 'zoho_quote_reconciled');
            }
        }
        if ($export && $type === 'invoice' && ! empty($record['invoice_id'])) {
            $export->update(['invoice_id' => (string) $record['invoice_id'], 'conversion_state' => 'converted', 'converted_at' => $export->converted_at ?? now()]);
        }
        return $export;
    }

    private function result(ZohoQuoteExport $export): array
    {
        return ['state' => $export->state, 'estimate_id' => $export->estimate_id, 'message' => $export->state === 'deleted'
            ? 'This quote was deleted in Zoho; re-export is blocked.' : 'Draft saved in Zoho Books. The accountant reviews it and creates the invoice there.'];
    }

    private function conversionResult(ZohoQuoteExport $export): array
    {
        return ['state' => $export->conversion_state, 'estimate_id' => $export->estimate_id, 'invoice_id' => $export->invoice_id,
            'message' => $export->invoice_id ? 'The existing Zoho quote is linked to one invoice.' : 'Invoice conversion is pending reconciliation.'];
    }

    private function audit(ZohoQuoteExport $export, string $action): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'action' => $action, 'subject_type' => 'ZohoQuoteExport', 'subject_id' => $export->id,
            'metadata' => ['reference' => $export->reference, 'estimate_id' => $export->estimate_id],
            'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);
    }
}
