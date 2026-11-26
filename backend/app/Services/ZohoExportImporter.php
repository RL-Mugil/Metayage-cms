<?php

namespace App\Services;

use App\Models\Client;
use App\Models\PatentInvoiceIn;
use App\Models\ZohoInvoice;
use App\Models\ZohoOpeningBalance;
use App\Models\ZohoSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Zoho Books exports (Invoice.csv, Quote.csv, Customer_Payment.csv, Credit_Note.csv,
 * Contacts.csv) into the portal, as an alternative to the API. Each document is rebuilt in the
 * shape the API returns and written to the same mirror (zoho_invoices) and snapshots
 * (zoho_snapshots), so the existing hooks fill the register, payments and client ledgers
 * exactly as for an API sync. Keyed by Zoho ID: a record already in the portal is skipped when
 * identical and updated when Zoho changed it; nothing is ever duplicated.
 *
 * Contacts update only the Zoho side (the customer snapshot and its opening balance): portal
 * clients are never edited. A full export can also remove records Zoho no longer has, when the
 * importer asks for it and the number missing is plausible.
 */
class ZohoExportImporter
{
    /** Header column that identifies each export (checked in this order: Contacts last). */
    public const KINDS = ['invoices' => 'Invoice ID', 'quotes' => 'Quote ID', 'payments' => 'CustomerPayment ID',
        'creditnotes' => 'CreditNotes ID', 'bills' => 'Bill ID', 'expenses' => 'Expense Reference ID', 'contacts' => 'Contact ID'];

    /** Records missing from a full export are removed only up to this many, and at most 10% of the portal's. */
    public const MAX_REMOVALS = 50;

    /** Which export a file is, from its header row. */
    public function detect(string $path): string
    {
        $header = $this->header($path);
        foreach (self::KINDS as $kind => $column) {
            if (in_array($column, $header, true)) {
                return $kind;
            }
        }
        throw new RuntimeException('Not a Zoho Books invoice, quote, customer payment, credit note, bill, expense or contacts export (header not recognised).');
    }

    /**
     * Preview or import one export file.
     *
     * @return array<string, mixed> report: kind, counts (new/changed/identical), totals, changes, cross-check
     */
    public function run(string $path, bool $write, bool $removeMissing = false): array
    {
        $kind = $this->detect($path);
        if ($kind === 'bills' || $kind === 'expenses') {
            return $this->runCosts($kind, $path, $write, $removeMissing);
        }
        $documents = match ($kind) {
            'payments' => $this->payments($path),
            'creditnotes' => $this->creditNotes($path),
            'contacts' => $this->contacts($path),
            default => $this->documents($path, $kind),
        };
        $report = ['kind' => $kind, 'documents' => count($documents), 'new' => 0, 'changed' => 0, 'identical' => 0,
            'changes' => [], 'cross_check' => [], 'totals' => ['total' => 0.0, 'balance' => 0.0]];

        if ($kind === 'creditnotes' || $kind === 'contacts') {
            return $this->runOther($kind, $documents, $report, $write, $removeMissing);
        }

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

        return $this->missing($kind, $documents, $report, $write && $removeMissing);
    }

    /** Zoho bills / expenses → matter costs (matter accounts only), tied to a case by docket code. */
    private function runCosts(string $kind, string $path, bool $write, bool $removeMissing): array
    {
        $costs = app(ZohoCostImporter::class);
        $num = fn ($v) => $this->num($v);
        $date = fn ($v) => $this->date($v);
        $parsed = $kind === 'bills' ? $costs->bills($this->rows($path), $num, $date) : $costs->expenses($this->rows($path), $num, $date);
        $docs = $parsed['docs'];
        $projects = \App\Models\Project::whereNotNull('docket_number')->get(['id', 'client_id', 'docket_number', 'project_code']);
        $report = ['kind' => $kind, 'documents' => count($docs), 'new' => 0, 'changed' => 0, 'identical' => 0, 'changes' => [], 'cross_check' => [],
            'totals' => ['total' => 0.0, 'balance' => 0.0], 'unassigned' => 0, 'skipped_overhead' => $parsed['skipped']];
        foreach ($docs as $doc) {
            $project = $costs->projectFor($doc['text'], $projects);
            $state = $costs->compare($doc, $project);
            $report[$state['state']]++;
            if ($state['state'] === 'changed' && count($report['changes']) < 200) {
                $report['changes'][] = ['number' => $doc['number'], 'what' => $state['what']];
            }
            $report['totals']['total'] += $doc['amount'] * $doc['exchange_rate'];
            if (! $project && ! \App\Models\MatterCost::where('zoho_id', $doc['zoho_id'])->whereNotNull('project_id')->exists()) {
                $report['unassigned']++;
            }
            if ($write && $state['state'] !== 'identical') {
                DB::transaction(fn () => $costs->write($doc, $project));
            }
        }
        $report['totals'] = array_map(fn ($v) => round($v, 2), $report['totals']);

        $source = $kind === 'bills' ? 'zoho_bill' : 'zoho_expense';
        $missing = $costs->missing($source, $docs);
        $portal = \App\Models\MatterCost::where('source', $source)->count();
        $removable = $docs !== [] && $missing->isNotEmpty() && $missing->count() <= self::MAX_REMOVALS && $missing->count() <= max(1, (int) floor($portal * 0.1));
        $report += ['missing' => $missing->count(), 'missing_sample' => $missing->take(50)->map(fn ($c) => (string) ($c->vendor_reference ?: $c->zoho_id))->values()->all(),
            'removable' => $removable, 'removed' => 0];
        if ($write && $removeMissing && $removable) {
            $report['removed'] = \App\Models\MatterCost::whereIn('id', $missing->pluck('id'))->delete();
        }

        return $report;
    }

    /** Credit notes and contacts: compare, then write. */
    private function runOther(string $kind, array $documents, array $report, bool $write, bool $removeMissing): array
    {
        $unlinked = 0;
        foreach ($documents as $doc) {
            $state = $kind === 'creditnotes' ? $this->compareCreditNote($doc) : $this->compareContact($doc);
            $report[$state['state']]++;
            if ($state['state'] === 'changed' && count($report['changes']) < 200) {
                $report['changes'][] = ['number' => $doc['number'], 'what' => $state['what']];
            }
            if ($kind === 'creditnotes') {
                if ($doc['status'] !== 'void') {
                    $report['totals']['total'] += $doc['total'] * $doc['exchange_rate'];
                    $report['totals']['balance'] += $doc['balance'] * $doc['exchange_rate'];
                }
            } else {
                $report['totals']['total'] += (float) ($doc['opening_balance'] ?? 0) * $doc['opening_rate'];
                if (! $this->clientForContact($doc['id'])) {
                    $unlinked++;
                }
            }
            if ($write && $state['state'] !== 'identical') {
                DB::transaction(fn () => $kind === 'creditnotes' ? $this->writeCreditNote($doc) : $this->writeContact($doc));
            }
        }
        $report['totals'] = array_map(fn ($v) => round($v, 2), $report['totals']);
        if ($kind === 'contacts') {
            // Customers with no portal client linked: shown only; portal clients are never edited here.
            $report['unlinked'] = $unlinked;

            return $report + ['missing' => 0, 'missing_sample' => [], 'removable' => false, 'removed' => 0];
        }

        return $this->missing($kind, $documents, $report, $write && $removeMissing);
    }

    /**
     * Records the portal has that this export does not. Removed only on request (a full export)
     * and when plausible: at most MAX_REMOVALS and at most 10% of the portal's records of this kind.
     * Each removal goes through the same deletion path as the API sync.
     */
    private function missing(string $kind, array $documents, array $report, bool $remove): array
    {
        $resource = ['invoices' => 'invoices', 'quotes' => 'estimates', 'payments' => 'customerpayments', 'creditnotes' => 'creditnotes'][$kind];
        $inFile = array_flip(array_map(fn ($d) => (string) $d['id'], $documents));
        if (in_array($kind, ['invoices', 'quotes'], true)) {
            $portal = ZohoInvoice::where('scope', $this->scope())->where('zoho_type', $kind === 'quotes' ? 'quote' : 'invoice')
                ->pluck('number', 'zoho_id')->mapWithKeys(fn ($n, $id) => [(string) $id => (string) $n])->all();
        } else {
            $portal = [];
            foreach (ZohoSnapshot::where('scope', $this->scope())->where('resource', $resource)->get() as $s) {
                $doc = json_decode((string) ($s->detail ?: $s->summary), true) ?: [];
                $portal[(string) $s->remote_id] = (string) ($doc['payment_number'] ?? $doc['creditnote_number'] ?? $s->remote_id);
            }
        }
        $missing = array_diff_key($portal, $inFile);
        $removable = $documents !== [] && $missing !== [] && count($missing) <= self::MAX_REMOVALS && count($missing) <= max(1, (int) floor(count($portal) * 0.1));
        $report['missing'] = count($missing);
        $report['missing_sample'] = array_slice(array_values($missing), 0, 50);
        $report['removable'] = $removable;
        $report['removed'] = 0;
        if ($remove && $removable) {
            $lifecycle = app(ZohoRecordLifecycle::class);
            foreach (array_keys($missing) as $id) {
                $lifecycle->delete($this->scope(), $resource, (string) $id, 'export_missing');
                $report['removed']++;
            }
        }

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
            $withheld = $this->num($row['Withholding Tax Amount'] ?? '');
            $invoiceNumber = trim($row['Invoice Number'] ?? '');
            if (($applied > 0 || $withheld > 0) && $invoiceNumber !== '') {
                $docs[$id]['invoices'][] = ['invoice_number' => $invoiceNumber, 'amount_applied' => $applied, 'tax_amount_withheld' => $withheld];
            }
        }

        return array_values($docs);
    }

    /** Credit notes, with the invoice each was applied to (the credited amount is its total less its balance). */
    private function creditNotes(string $path): array
    {
        $docs = [];
        foreach ($this->rows($path) as $row) {
            $id = trim($row['CreditNotes ID'] ?? '');
            if ($id === '') {
                continue;
            }
            $docs[$id] ??= [
                'id' => $id,
                'number' => trim($row['Credit Note Number'] ?? ''),
                'date' => $this->date($row['Credit Note Date'] ?? ''),
                'status' => strtolower(trim($row['Credit Note Status'] ?? '')),
                'customer_id' => trim($row['Customer ID'] ?? ''),
                'customer_name' => trim($row['Customer Name'] ?? ''),
                'currency' => trim($row['Currency Code'] ?? '') ?: 'INR',
                'exchange_rate' => $this->num($row['Exchange Rate'] ?? '1') ?: 1.0,
                'total' => $this->num($row['Total'] ?? ''),
                'balance' => $this->num($row['Balance'] ?? ''),
                'invoice_number' => '',
            ];
            $applied = trim($row['Applied Invoice Number'] ?? '') ?: trim($row['Associated Invoice Number'] ?? '');
            if ($docs[$id]['invoice_number'] === '' && $applied !== '') {
                $docs[$id]['invoice_number'] = $applied;
            }
        }

        return array_values($docs);
    }

    /** Customers: the Zoho fields the portal uses (no personal contact details are kept). */
    private function contacts(string $path): array
    {
        $docs = [];
        foreach ($this->rows($path) as $row) {
            $id = trim($row['Contact ID'] ?? '');
            if ($id === '' || strtolower(trim($row['Contact Type'] ?? 'customer')) !== 'customer') {
                continue;
            }
            $opening = trim((string) ($row['Opening Balance'] ?? ''));
            $docs[$id] = [
                'id' => $id,
                'number' => trim($row['Display Name'] ?? '') ?: trim($row['Company Name'] ?? '') ?: $id,
                'company_name' => trim($row['Company Name'] ?? ''),
                'contact_name' => trim($row['Display Name'] ?? ''),
                'customer_number' => trim($row['Customer Number'] ?? ''),
                'status' => strtolower(trim($row['Status'] ?? '')),
                'currency' => trim($row['Currency Code'] ?? '') ?: 'INR',
                'gst_no' => trim($row['GST Identification Number (GSTIN)'] ?? ''),
                'gst_treatment' => trim($row['GST Treatment'] ?? ''),
                // null = the export did not say (left as it is); 0 = Zoho shows no opening balance.
                'opening_balance' => $opening === '' ? null : $this->num($opening),
                'opening_rate' => $this->num($row['Opening Balance Exchange Rate'] ?? '1') ?: 1.0,
            ];
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
                $invoices[] = ['invoice_id' => $invoiceId, 'invoice_number' => $applied['invoice_number'], 'amount_applied' => $applied['amount_applied'],
                    'tax_amount_withheld' => $applied['tax_amount_withheld'] ?? 0];
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
        $applied = array_map(fn ($i) => ($i['invoice_id'] ?? '').':'.round((float) ($i['amount_applied'] ?? 0), 2)
            .(((float) ($i['tax_amount_withheld'] ?? 0)) > 0 ? ':t'.round((float) $i['tax_amount_withheld'], 2) : ''), $detail['invoices'] ?? []);
        sort($applied);

        return ($detail['date'] ?? '').'|'.round((float) ($detail['amount'] ?? 0), 2).'|u'.round((float) ($detail['unused_amount'] ?? 0), 2).'|'.implode(',', $applied);
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
            $what[] = 'Amount, unused amount, date or invoices applied';
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

    // ── Credit notes ─────────────────────────────────────────────────────────

    private function creditNoteDetail(array $doc): array
    {
        $credited = [];
        $amount = round($doc['total'] - $doc['balance'], 2);
        if ($doc['invoice_number'] !== '' && $amount > 0 && $doc['status'] !== 'void') {
            $invoiceId = ZohoInvoice::withTrashed()->where('zoho_type', 'invoice')->where('number', $doc['invoice_number'])->value('zoho_id');
            if ($invoiceId) {
                $credited[] = ['invoice_id' => (string) $invoiceId, 'invoice_number' => $doc['invoice_number'], 'credited_amount' => $amount, 'date' => $doc['date']];
            }
        }

        return [
            'creditnote_id' => $doc['id'], 'creditnote_number' => $doc['number'], 'date' => $doc['date'], 'status' => $doc['status'],
            'customer_id' => $doc['customer_id'], 'customer_name' => $doc['customer_name'], 'currency_code' => $doc['currency'],
            'exchange_rate' => $doc['exchange_rate'], 'total' => $doc['total'], 'balance' => $doc['balance'],
            'invoices_credited' => $credited, 'source' => 'zoho_export',
        ];
    }

    private function creditNoteKey(array $d): string
    {
        $credited = array_map(fn ($i) => ($i['invoice_id'] ?? '').':'.round((float) ($i['credited_amount'] ?? $i['amount_credited'] ?? 0), 2), $d['invoices_credited'] ?? []);
        sort($credited);

        return ($d['date'] ?? '').'|'.strtolower((string) ($d['status'] ?? '')).'|'.round((float) ($d['total'] ?? 0), 2).'|'.round((float) ($d['balance'] ?? 0), 2).'|'.implode(',', $credited);
    }

    private function compareCreditNote(array $doc): array
    {
        $snapshot = $this->snapshot('creditnotes', $doc['id']);
        if (! $snapshot) {
            return ['state' => 'new', 'what' => []];
        }
        $what = $snapshot->trashed() ? ['Restored (deleted earlier)'] : [];
        if ($this->creditNoteKey(json_decode((string) ($snapshot->detail ?: $snapshot->summary), true) ?: []) !== $this->creditNoteKey($this->creditNoteDetail($doc))) {
            $what[] = 'Amount, balance, status or invoice credited';
        }

        return ['state' => $what ? 'changed' : 'identical', 'what' => $what];
    }

    private function writeCreditNote(array $doc): void
    {
        $detail = $this->creditNoteDetail($doc);
        $snapshot = $this->snapshot('creditnotes', $doc['id']);
        if (! $snapshot) {
            $json = json_encode($detail);
            ZohoSnapshot::create(['scope' => $this->scope(), 'resource' => 'creditnotes', 'remote_id' => $doc['id'],
                'summary' => $json, 'detail' => $json, 'fingerprint' => hash('sha256', $json), 'detail_synced_at' => now()]);

            return;
        }
        $snapshot->restore();
        $merged = array_merge(json_decode((string) $snapshot->detail, true) ?: [], $detail);
        $snapshot->update(['detail' => json_encode($merged), 'detail_synced_at' => now()]);
    }

    // ── Contacts (Zoho side only: portal clients are never edited) ───────────

    /** The portal client already linked to this Zoho customer (read only). */
    private function clientForContact(string $contactId): ?int
    {
        return Client::where('zoho_contact_id', $contactId)->value('id')
            ?? ZohoInvoice::withTrashed()->where('zoho_contact_id', $contactId)->whereNotNull('client_id')->value('client_id');
    }

    private function contactDetail(array $doc): array
    {
        return [
            'contact_id' => $doc['id'], 'contact_name' => $doc['contact_name'], 'company_name' => $doc['company_name'],
            // Zoho's API field for the customer number (the portal client code).
            'contact_number' => $doc['customer_number'], 'contact_type' => 'customer', 'status' => $doc['status'],
            'currency_code' => $doc['currency'], 'gst_no' => $doc['gst_no'], 'gst_treatment' => $doc['gst_treatment'],
            'source' => 'zoho_export',
        ] + ($doc['opening_balance'] === null ? [] : ['opening_balance_amount' => $doc['opening_balance']]);
    }

    private function openingTotal(string $contactId): float
    {
        return round((float) ZohoOpeningBalance::withoutGlobalScopes()->where('scope', $this->scope())->where('zoho_contact_id', $contactId)->sum('amount'), 2);
    }

    private function compareContact(array $doc): array
    {
        $snapshot = $this->snapshot('contacts', $doc['id']);
        if (! $snapshot) {
            return ['state' => 'new', 'what' => []];
        }
        $saved = json_decode((string) ($snapshot->detail ?: $snapshot->summary), true) ?: [];
        $what = [];
        foreach (['contact_name' => 'Name', 'company_name' => 'Company', 'status' => 'Status', 'currency_code' => 'Currency', 'gst_no' => 'GSTIN'] as $key => $label) {
            $now = (string) ($this->contactDetail($doc)[$key] ?? '');
            if ($now !== '' && strtolower($now) !== strtolower((string) ($saved[$key] ?? ''))) {
                $what[] = $label;
            }
        }
        if ($doc['opening_balance'] !== null && abs($this->openingTotal($doc['id']) - $doc['opening_balance']) > 0.009) {
            $what[] = 'Opening balance ₹'.number_format($this->openingTotal($doc['id']), 2).' → ₹'.number_format($doc['opening_balance'], 2);
        }

        return ['state' => $what ? 'changed' : 'identical', 'what' => $what];
    }

    /**
     * The customer snapshot (merged: fields the export lacks, such as Zoho's balances, are kept) and
     * the opening balance: its amount from the export; the part already paid is kept from the
     * last API reading (an export does not say it), so a new opening balance starts unpaid.
     */
    private function writeContact(array $doc): void
    {
        $detail = $this->contactDetail($doc);
        $snapshot = $this->snapshot('contacts', $doc['id']);
        if (! $snapshot) {
            $json = json_encode($detail);
            ZohoSnapshot::create(['scope' => $this->scope(), 'resource' => 'contacts', 'remote_id' => $doc['id'],
                'summary' => $json, 'detail' => $json, 'fingerprint' => hash('sha256', $json), 'detail_synced_at' => now()]);
        } else {
            $snapshot->restore();
            $merged = array_merge(json_decode((string) $snapshot->detail, true) ?: [], $detail);
            $snapshot->update(['detail' => json_encode($merged), 'detail_synced_at' => now()]);
        }
        if ($doc['opening_balance'] === null) {
            return;
        }

        $rows = ZohoOpeningBalance::withoutGlobalScopes()->where('scope', $this->scope())->where('zoho_contact_id', $doc['id'])->get();
        $clientId = $this->clientForContact($doc['id']) ?? $rows->first()?->client_id;
        if ($doc['opening_balance'] <= 0) {
            $rows->each->delete();
        } else {
            $row = $rows->first();
            $paid = $row ? max(0, (float) $row->amount - (float) $row->balance) : 0.0;
            $values = ['client_id' => $clientId, 'customer_name' => $doc['company_name'] ?: $doc['contact_name'], 'amount' => $doc['opening_balance'],
                'balance' => max(0, round($doc['opening_balance'] - $paid, 2)), 'currency' => $doc['currency'], 'source' => 'Zoho Opening Balance', 'synced_at' => now()];
            if ($row) {
                $row->update($values + ['balance_date' => ZohoOpeningBalanceMirror::openingDate()]);
                $rows->slice(1)->each->delete();
            } else {
                ZohoOpeningBalance::create($values + ['scope' => $this->scope(), 'zoho_contact_id' => $doc['id'], 'location_id' => 'default',
                    'balance_date' => ZohoOpeningBalanceMirror::openingDate()]);
            }
        }
        if ($clientId) {
            ClientLedgerSync::queue((int) $clientId);
        }
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
