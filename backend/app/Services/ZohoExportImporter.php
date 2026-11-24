<?php

namespace App\Services;

use App\Models\PatentInvoiceIn;
use App\Models\ZohoInvoice;
use App\Models\ZohoSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Zoho Books exports (Invoice.csv, Quote.csv, Customer_Payment.csv) into the portal, as an
 * alternative to the API. Each document is rebuilt in the shape the API returns and written
 * to the same mirror (zoho_invoices) and snapshots (zoho_snapshots), so the existing hooks
 * fill the register, payments and client ledgers exactly as for an API sync. Keyed by Zoho
 * ID: a record already in the portal is skipped when identical and updated when Zoho changed
 * it; nothing is ever duplicated.
 */
class ZohoExportImporter
{
    public const KINDS = ['invoices' => 'Invoice ID', 'quotes' => 'Quote ID', 'payments' => 'CustomerPayment ID'];

    /** Which export a file is, from its header row. */
    public function detect(string $path): string
    {
        $header = $this->header($path);
        foreach (self::KINDS as $kind => $column) {
            if (in_array($column, $header, true)) {
                return $kind;
            }
        }
        throw new RuntimeException('Not a Zoho Books invoice, quote or customer payment export (header not recognised).');
    }

    /**
     * Preview or import one export file.
     *
     * @return array<string, mixed> report: kind, counts (new/changed/identical), totals, changes, cross-check
     */
    public function run(string $path, bool $write): array
    {
        $kind = $this->detect($path);
        $documents = $kind === 'payments' ? $this->payments($path) : $this->documents($path, $kind);
        $report = ['kind' => $kind, 'documents' => count($documents), 'new' => 0, 'changed' => 0, 'identical' => 0,
            'changes' => [], 'cross_check' => [], 'totals' => ['total' => 0.0, 'balance' => 0.0]];

        foreach ($documents as $doc) {
            $state = $kind === 'payments' ? $this->comparePayment($doc) : $this->compareDocument($doc, $kind);
            $report[$state['state']]++;
            if ($state['state'] === 'changed' && count($report['changes']) < 200) {
                $report['changes'][] = ['number' => $doc['number'], 'what' => $state['what']];
            }
            if ($kind !== 'payments') {
                if (! in_array(strtolower($doc['status']), ['void', 'draft'], true)) {
                    $report['totals']['total'] += $doc['total'] * $doc['exchange_rate'];
                    $report['totals']['balance'] += ($doc['balance'] ?? 0) * $doc['exchange_rate'];
                }
                if ($mismatch = $this->crossCheck($doc)) {
                    $report['cross_check'][] = $mismatch;
                }
            } else {
                $report['totals']['total'] += $doc['amount'] * $doc['exchange_rate'];
                $report['totals']['balance'] += $doc['unused'];
            }
            if ($write && $state['state'] !== 'identical') {
                DB::transaction(fn () => $kind === 'payments' ? $this->writePayment($doc) : $this->writeDocument($doc, $kind));
            }
        }
        $report['totals'] = array_map(fn ($v) => round($v, 2), $report['totals']);
        $report['cross_check'] = array_slice($report['cross_check'], 0, 100);

        return $report;
    }

    /** Export totals beside the portal's, for the reconciliation panel. */
    public function reconcile(array $reports): array
    {
        $out = [];
        $invoices = collect($reports)->firstWhere('kind', 'invoices');
        if ($invoices) {
            $register = PatentInvoiceIn::where('type', 'invoice')->where('source', 'zoho')->whereNotIn('status', ['Draft', 'Cancelled']);
            $billed = (float) (clone $register)->sum(DB::raw('COALESCE(total_bcy, invoice_amount)'));
            $balance = (float) (clone $register)->sum(DB::raw('COALESCE(balance_due * COALESCE(exchange_rate, 1), 0)'));
            $out['invoices'] = [
                'export' => ['count' => $invoices['documents'], 'billed' => $invoices['totals']['total'], 'outstanding' => $invoices['totals']['balance'],
                    'collected' => round($invoices['totals']['total'] - $invoices['totals']['balance'], 2)],
                'portal' => ['count' => (clone $register)->count(), 'billed' => round($billed, 2), 'outstanding' => round($balance, 2),
                    'collected' => round($billed - $balance, 2)],
            ];
        }
        $quotes = collect($reports)->firstWhere('kind', 'quotes');
        if ($quotes) {
            $register = PatentInvoiceIn::where('type', 'quote')->where('source', 'zoho');
            $out['quotes'] = [
                'export' => ['count' => $quotes['documents'], 'total' => $quotes['totals']['total']],
                'portal' => ['count' => (clone $register)->count(), 'total' => round((float) (clone $register)->sum(DB::raw('COALESCE(total_bcy, invoice_amount)')), 2)],
            ];
        }

        return $out;
    }

    // ── Parsing ──────────────────────────────────────────────────────────────

    private function header(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, null, ",", "\"", "") ?: [];
        fclose($handle);
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        }

        return array_map('trim', $header);
    }

    /** @return iterable<array<string, string>> */
    private function rows(string $path): iterable
    {
        $handle = fopen($path, 'r');
        $header = null;
        while (($line = fgetcsv($handle, null, ",", "\"", "")) !== false) {
            if ($header === null) {
                $line[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $line[0]);
                $header = array_map('trim', $line);
                continue;
            }
            if (count($line) === 1 && trim((string) $line[0]) === '') {
                continue;
            }
            yield array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), ''));
        }
        fclose($handle);
    }

    private function num(?string $value): float
    {
        $value = str_replace([',', ' '], '', (string) $value);

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function date(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Zoho export status words → the API's. */
    private function status(string $status): string
    {
        $s = strtolower(str_replace([' ', '_'], '', $status));

        return match ($s) {
            'closed', 'paid' => 'paid',
            'partiallypaid' => 'partially_paid',
            default => strtolower(str_replace(' ', '_', trim($status))),
        };
    }

    /** Invoices or quotes, one document per Zoho ID with its line items and taxes. */
    private function documents(string $path, string $kind): array
    {
        $isQuote = $kind === 'quotes';
        $docs = [];
        foreach ($this->rows($path) as $row) {
            $id = trim($row[$isQuote ? 'Quote ID' : 'Invoice ID'] ?? '');
            if ($id === '') {
                continue;
            }
            $docs[$id] ??= [
                'id' => $id,
                'number' => trim($row[$isQuote ? 'Quote Number' : 'Invoice Number'] ?? ''),
                'date' => $this->date($row[$isQuote ? 'Quote Date' : 'Invoice Date'] ?? ''),
                'due_date' => $this->date($row[$isQuote ? 'Expiry Date' : 'Due Date'] ?? ''),
                'status' => $this->status($row[$isQuote ? 'Quote Status' : 'Invoice Status'] ?? ''),
                'customer_id' => trim($row['Customer ID'] ?? ''),
                'customer_name' => trim($row['Customer Name'] ?? ''),
                'currency' => trim($row['Currency Code'] ?? '') ?: 'INR',
                'exchange_rate' => $this->num($row['Exchange Rate'] ?? '1') ?: 1.0,
                'sub_total' => $this->num($row['SubTotal'] ?? ''),
                'total' => $this->num($row['Total'] ?? ''),
                'balance' => $isQuote ? null : $this->num($row['Balance'] ?? ''),
                'last_payment_date' => $isQuote ? null : $this->date($row['Last Payment Date'] ?? ''),
                'place_of_supply' => trim($row['Place of Supply'] ?? ''),
                'application_no' => trim((string) ($row['CF.Application No:'] ?? ''), " '-"),
                'patent_office' => trim($row['CF.Patent Office:'] ?? ''),
                'estimate_number' => trim($row['Estimate Number'] ?? ''),
                'cf' => [
                    'service' => $this->num($row['CF.Service Fee'] ?? ''),
                    'office' => $this->num($row['CF.Patent Office Fee'] ?? ''),
                    'stamp' => $this->num($row['CF.Stamp Duty'] ?? ''),
                ],
                'line_items' => [],
                'taxes' => ['CGST' => 0.0, 'SGST' => 0.0, 'IGST' => 0.0],
            ];
            $docs[$id]['line_items'][] = [
                'name' => trim($row['Item Name'] ?? ''),
                'description' => trim($row['Item Desc'] ?? ''),
                'account_name' => trim($row['Account'] ?? ''),
                'quantity' => $this->num($row['Quantity'] ?? '1') ?: 1,
                'item_total' => $this->num($row['Item Total'] ?? ''),
            ];
            foreach (['CGST', 'SGST', 'IGST'] as $tax) {
                $docs[$id]['taxes'][$tax] += $this->num($row[$tax] ?? '');
            }
        }

        return array_values($docs);
    }

    /** Customer payments, with the invoices each was applied to. */
    private function payments(string $path): array
    {
        $docs = [];
        foreach ($this->rows($path) as $row) {
            $id = trim($row['CustomerPayment ID'] ?? '');
            if ($id === '') {
                continue;
            }
            $docs[$id] ??= [
                'id' => $id,
                'number' => trim($row['Payment Number'] ?? ''),
                'date' => $this->date($row['Date'] ?? ''),
                'amount' => $this->num($row['Amount'] ?? ''),
                'unused' => $this->num($row['Unused Amount'] ?? ''),
                'exchange_rate' => $this->num($row['Exchange Rate'] ?? '1') ?: 1.0,
                'currency' => trim($row['Currency Code'] ?? '') ?: 'INR',
                'mode' => trim($row['Mode'] ?? ''),
                'reference' => trim($row['Reference Number'] ?? ''),
                'customer_id' => trim($row['CustomerID'] ?? ''),
                'customer_name' => trim($row['Customer Name'] ?? ''),
                'invoices' => [],
            ];
            $applied = $this->num($row['Amount Applied to Invoice'] ?? '');
            $invoiceNumber = trim($row['Invoice Number'] ?? '');
            if ($applied > 0 && $invoiceNumber !== '') {
                $docs[$id]['invoices'][] = ['invoice_number' => $invoiceNumber, 'amount_applied' => $applied];
            }
        }

        return array_values($docs);
    }

    // ── Compare and write ────────────────────────────────────────────────────

    private function scope(): string
    {
        return app(ZohoBooksService::class)->scope();
    }

    /** The API-shaped detail for an invoice or estimate. */
    private function detailFor(array $doc, string $kind): array
    {
        $taxes = [];
        foreach ($doc['taxes'] as $name => $amount) {
            if ($amount != 0.0) {
                $taxes[] = ['tax_name' => $name, 'tax_amount' => round($amount, 2)];
            }
        }

        return [
            $kind === 'quotes' ? 'estimate_id' : 'invoice_id' => $doc['id'],
            $kind === 'quotes' ? 'estimate_number' : 'invoice_number' => $doc['number'],
            'date' => $doc['date'], 'due_date' => $doc['due_date'], 'status' => $doc['status'],
            'customer_id' => $doc['customer_id'], 'customer_name' => $doc['customer_name'],
            'currency_code' => $doc['currency'], 'exchange_rate' => $doc['exchange_rate'],
            'sub_total' => $doc['sub_total'], 'total' => $doc['total'], 'balance' => $doc['balance'],
            'last_payment_date' => $doc['last_payment_date'], 'place_of_supply' => $doc['place_of_supply'],
            'cf_application_no' => $doc['application_no'], 'cf_patent_office' => $doc['patent_office'],
            'line_items' => $doc['line_items'], 'taxes' => $taxes, 'source' => 'zoho_export',
        ];
    }

    /** new / changed / identical, by Zoho ID, and what changed. */
    private function compareDocument(array $doc, string $kind): array
    {
        $type = $kind === 'quotes' ? 'quote' : 'invoice';
        $mirror = ZohoInvoice::withTrashed()->where('zoho_type', $type)->where('zoho_id', $doc['id'])->first();
        if (! $mirror) {
            return ['state' => 'new', 'what' => []];
        }
        $what = [];
        if (abs((float) $mirror->total - $doc['total']) > 0.009) {
            $what[] = 'Total ₹'.number_format((float) $mirror->total).' → ₹'.number_format($doc['total']);
        }
        if ($type === 'invoice' && abs((float) $mirror->balance - (float) $doc['balance']) > 0.009) {
            $what[] = 'Balance ₹'.number_format((float) $mirror->balance).' → ₹'.number_format((float) $doc['balance']);
        }
        if (strtolower((string) $mirror->status) !== $doc['status']) {
            $what[] = 'Status '.$mirror->status.' → '.$doc['status'];
        }
        if ($mirror->txn_date?->toDateString() !== $doc['date']) {
            $what[] = 'Date '.$mirror->txn_date?->toDateString().' → '.$doc['date'];
        }
        if ($mirror->trashed()) {
            $what[] = 'Restored (deleted earlier)';
        }
        $snapshot = $this->snapshot($kind === 'quotes' ? 'estimates' : 'invoices', $doc['id']);
        $existing = $snapshot?->detail ? (json_decode($snapshot->detail, true)['line_items'] ?? []) : [];
        if ($this->itemsKey($existing) !== $this->itemsKey($doc['line_items'])) {
            $what[] = 'Line items';
        }

        return ['state' => $what ? 'changed' : 'identical', 'what' => $what];
    }

    private function itemsKey(array $items): string
    {
        $keys = array_map(fn ($i) => strtoupper(trim((string) ($i['name'] ?? ''))).':'.round((float) ($i['item_total'] ?? 0), 2), $items);
        sort($keys);

        return implode('|', $keys);
    }

    private function snapshot(string $resource, string $id): ?ZohoSnapshot
    {
        return ZohoSnapshot::withTrashed()->where('scope', $this->scope())->where('resource', $resource)->where('remote_id', $id)->first();
    }

    /** Snapshot first (the line items the register reads), then the mirror row (its save imports). */
    private function writeDocument(array $doc, string $kind): void
    {
        $type = $kind === 'quotes' ? 'quote' : 'invoice';
        $resource = $kind === 'quotes' ? 'estimates' : 'invoices';
        $detail = $this->detailFor($doc, $kind);
        $snapshot = $this->snapshot($resource, $doc['id']);
        $existingItems = $snapshot?->detail ? (json_decode($snapshot->detail, true)['line_items'] ?? null) : null;
        if (! $snapshot) {
            $json = json_encode($detail);
            ZohoSnapshot::create(['scope' => $this->scope(), 'resource' => $resource, 'remote_id' => $doc['id'],
                'summary' => $json, 'detail' => $json, 'fingerprint' => hash('sha256', $json), 'detail_synced_at' => now()]);
        } elseif ($existingItems === null || $this->itemsKey($existingItems) !== $this->itemsKey($doc['line_items'])) {
            // Only the detail: the API's summary fingerprint stays as the API left it.
            $merged = array_merge(json_decode((string) $snapshot->detail, true) ?: [], $detail);
            $snapshot->restore();
            $snapshot->update(['detail' => json_encode($merged), 'detail_synced_at' => now()]);
        }

        $mirror = ZohoInvoice::withTrashed()->where('zoho_type', $type)->where('zoho_id', $doc['id'])->first();
        $attrs = [
            'zoho_contact_id' => $doc['customer_id'] ?: null, 'number' => $doc['number'], 'status' => $doc['status'],
            'total' => $doc['total'], 'balance' => $doc['balance'], 'currency' => $doc['currency'],
            'txn_date' => $doc['date'], 'due_date' => $doc['due_date'],
            'application_no' => $doc['application_no'] ?: null, 'patent_office' => $doc['patent_office'] ?: null,
            'synced_at' => now(),
        ];
        if ($mirror) {
            if ($mirror->trashed()) {
                $mirror->restore();
            }
            $mirror->update($attrs);
        } else {
            ZohoInvoice::create($attrs + ['scope' => $this->scope(), 'zoho_type' => $type, 'zoho_id' => $doc['id'], 'match_source' => 'file']);
        }
    }

    private function paymentDetail(array $doc): array
    {
        $invoices = [];
        foreach ($doc['invoices'] as $applied) {
            $invoiceId = ZohoInvoice::withTrashed()->where('zoho_type', 'invoice')->where('number', $applied['invoice_number'])->value('zoho_id');
            if ($invoiceId) {
                $invoices[] = ['invoice_id' => $invoiceId, 'invoice_number' => $applied['invoice_number'], 'amount_applied' => $applied['amount_applied']];
            }
        }

        return [
            'payment_id' => $doc['id'], 'payment_number' => $doc['number'], 'date' => $doc['date'],
            'amount' => $doc['amount'], 'unused_amount' => $doc['unused'], 'exchange_rate' => $doc['exchange_rate'],
            'currency_code' => $doc['currency'], 'payment_mode' => $doc['mode'], 'reference_number' => $doc['reference'],
            'customer_id' => $doc['customer_id'], 'customer_name' => $doc['customer_name'],
            'invoices' => $invoices, 'source' => 'zoho_export',
        ];
    }

    private function paymentKey(array $detail): string
    {
        $applied = array_map(fn ($i) => ($i['invoice_id'] ?? '').':'.round((float) ($i['amount_applied'] ?? 0), 2), $detail['invoices'] ?? []);
        sort($applied);

        return ($detail['date'] ?? '').'|'.round((float) ($detail['amount'] ?? 0), 2).'|'.implode(',', $applied);
    }

    private function comparePayment(array $doc): array
    {
        $snapshot = $this->snapshot('customerpayments', $doc['id']);
        if (! $snapshot) {
            return ['state' => 'new', 'what' => []];
        }
        $existing = json_decode((string) ($snapshot->detail ?: $snapshot->summary), true) ?: [];
        $what = [];
        if ($snapshot->trashed()) {
            $what[] = 'Restored (deleted earlier)';
        }
        if ($this->paymentKey($existing) !== $this->paymentKey($this->paymentDetail($doc))) {
            $what[] = 'Amount, date or invoices applied';
        }

        return ['state' => $what ? 'changed' : 'identical', 'what' => $what];
    }

    private function writePayment(array $doc): void
    {
        $detail = $this->paymentDetail($doc);
        $snapshot = $this->snapshot('customerpayments', $doc['id']);
        if (! $snapshot) {
            $json = json_encode($detail);
            ZohoSnapshot::create(['scope' => $this->scope(), 'resource' => 'customerpayments', 'remote_id' => $doc['id'],
                'summary' => $json, 'detail' => $json, 'fingerprint' => hash('sha256', $json), 'detail_synced_at' => now()]);

            return;
        }
        $snapshot->restore();
        $merged = array_merge(json_decode((string) $snapshot->detail, true) ?: [], $detail);
        $snapshot->update(['detail' => json_encode($merged), 'detail_synced_at' => now()]);
    }

    /** Zoho's own CF fee fields against the portal's line-item split (shown, never applied). */
    private function crossCheck(array $doc): ?array
    {
        if ($doc['cf']['service'] == 0.0 && $doc['cf']['office'] == 0.0 && $doc['cf']['stamp'] == 0.0) {
            return null;
        }
        $split = app(ZohoRegisterImporter::class)->split(['line_items' => $doc['line_items'], 'taxes' => []], $doc['total']);
        $office = $split['office'];
        $service = $split['service'] - $split['discount'];
        if (abs($office - $doc['cf']['office']) > 1 || abs($service - $doc['cf']['service']) > 1) {
            return ['number' => $doc['number'], 'zoho_office_fee' => $doc['cf']['office'], 'portal_office_fee' => round($office, 2),
                'zoho_service_fee' => $doc['cf']['service'], 'portal_service_fee' => round($service, 2)];
        }

        return null;
    }
}
