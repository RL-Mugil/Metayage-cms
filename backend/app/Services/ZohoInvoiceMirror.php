<?php

namespace App\Services;

use App\Models\ZohoInvoice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/** Called inside the sync transaction; preserves manual case links. */
class ZohoInvoiceMirror
{
    public function save(
        array $record,
        string $type,
        string $idField,
        string $numberField,
        ZohoCaseMatcher $matcher,
        Collection $uinIndex,
        Collection $docketIndex,
        int &$matched,
        int &$unmatched,
        ?Collection $prefixIndex = null,
    ): void {
        $zohoId = (string) ($record[$idField] ?? '');
        $number = (string) ($record[$numberField] ?? '');
        if ($zohoId === '' || $number === '') {
            return;
        }
        $scope = app(ZohoBooksService::class)->scope();
        if (app(ZohoRecordLifecycle::class)->isDeleted($scope, $type === 'invoice' ? 'invoices' : 'estimates', $zohoId)) {
            return;
        }

        $match = $matcher->match($number, $type, $uinIndex, $docketIndex, $prefixIndex);
        $export = app(ZohoQuoteWriter::class)->reconcile(app(ZohoBooksService::class)->scope(), $record, $type);
        if ($export) {
            $match = ['project_id' => $export->project_id, 'client_id' => $export->client_id, 'source' => 'export'];
        }
        if ($match['project_id']) {
            $matched++;
        } else {
            // Still mirrored (not skipped) — an unmatched record with no case link shows
            // up on the Financial Suite "Zoho Books" tab as "unmatched" so staff can link
            // it to a project manually via ZohoBooksController::linkProject().
            $unmatched++;
        }

        $attrs = [
            'zoho_contact_id' => $record['customer_id'] ?? null,
            'number'          => $number,
            'status'          => $record['status'] ?? null,
            'total'           => $record['total'] ?? 0,
            'balance'         => $record['balance'] ?? null,
            'currency'        => $record['currency_code'] ?? 'INR',
            'txn_date'        => $record['date'] ?? null,
            'due_date'        => $record['due_date'] ?? null,
            'url'             => $record['invoice_url'] ?? null,
            'application_no'  => $record['cf_application_no'] ?? null,
            'patent_office'   => $record['cf_patent_office'] ?? null,
            'synced_at'       => now(),
        ];

        foreach (['url' => 'invoice_url', 'application_no' => 'cf_application_no', 'patent_office' => 'cf_patent_office'] as $column => $field) {
            if (! array_key_exists($field, $record)) {
                unset($attrs[$column]);
            }
        }

        // A staff member may have manually linked this record to a project (see
        // ZohoBooksController::linkProject()) after the auto-matcher came up empty —
        // never let a re-sync silently overwrite that with the (still unmatched) auto result.
        $existing = ZohoInvoice::where('zoho_type', $type)->where('zoho_id', $zohoId)->lockForUpdate()->first();
        if ($existing && $existing->match_source === 'manual') {
            $existing->update($attrs);
            $this->invalidate($existing);
            return;
        }
        // List summaries may omit estimate_id/reference_number that detail reads contain.
        if (! $export && $existing?->match_source === 'export'
            && $existing->zoho_contact_id === (string) ($record['customer_id'] ?? '')) {
            $existing->update($attrs);
            $this->invalidate($existing);
            return;
        }

        $attrs['client_id']    = $match['client_id'];
        $attrs['project_id']   = $match['project_id'];
        $attrs['match_source'] = $match['source'];

        if ($existing) {
            $this->invalidate($existing);
        }
        $saved = ZohoInvoice::updateOrCreate(['scope' => $scope, 'zoho_type' => $type, 'zoho_id' => $zohoId], $attrs);
        $this->invalidate($saved);
    }
    private function invalidate(ZohoInvoice $record): void
    {
        ZohoRecordLifecycle::invalidate($record);
    }
}
