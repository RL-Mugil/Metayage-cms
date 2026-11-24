<?php

namespace App\Services;

use App\Models\Client;
use App\Models\PatentInvoiceIn;
use App\Models\Project;
use App\Models\ZohoInvoice;
use App\Models\ZohoQuoteExport;
use App\Models\ZohoSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Imports Zoho Books invoices and quotes into the portal's Indian Patents (UIN) register
 * (patent_invoices_in), the single source of truth every dashboard reads. Staff keep raising
 * them in Zoho; each mirror save (5-minute poll, nightly sync) re-imports the record.
 *
 * One register row per Zoho record (key: zoho_invoice_id). A portal quote already pushed to
 * Zoho, or a portal row with the same UIN and type, is linked instead of duplicated. Zoho
 * owns the amounts, dates and status of an imported row; the portal keeps its internal
 * fields (attorney / consultant / referral fees, net revenue, remarks).
 */
class ZohoRegisterImporter
{
    /**
     * The firm's Zoho items are named by code (POI, POF, PAS, PRV, ...). Patent Office fees:
     * POI (Indian Patent Office), POF (foreign patent offices), or the "Patent Office Fees
     * Reimbursement" account / "fixed by ... Patent Office" description.
     */
    public const OFFICE_FEE_CODES = ['POI', 'POF', 'PATENT OFFICES FEES', 'PATENT OFFICE FEES'];
    public const OFFICE_FEE_WORDS = ['patent office fees reimbursement', 'payable to indian patent office', 'fixed by patent office', 'fees fixed by patent office', 'patent office fee', 'official fee', 'government fee'];

    /** Disbursements: STP (stamp papers) and out-of-pocket costs. */
    public const EXPENSE_CODES = ['STP'];
    public const EXPENSE_WORDS = ['stamp paper', 'out of pocket', 'out-of-pocket', 'courier', 'postage', 'notar', 'legalis', 'apostille', 'disbursement', 'bank charge', 'travel'];

    /** Discount lines (negative amounts). */
    public const DISCOUNT_CODES = ['DISCOUNT'];

    /** @var array<string, int>|null client_code => id */
    private ?array $clientsByCode = null;

    /** @var array<string, int>|null zoho_contact_id => id */
    private ?array $clientsByContact = null;

    /** Unclassified line-item names seen in this run (for the dry-run report). */
    public array $unclassified = [];

    /** Count unclassified line items (off for the report's second pass over the same record). */
    private bool $tally = true;

    /** True during importAll(): payments and ledgers are rebuilt once at the end. */
    private bool $bulk = false;

    /**
     * @return array{action: string, row: ?PatentInvoiceIn} action: created | updated | linked | cancelled | skipped
     */
    public function import(ZohoInvoice $mirror, bool $dryRun = false): array
    {
        $type = $mirror->zoho_type === 'quote' ? 'quote' : 'invoice';
        $existing = PatentInvoiceIn::where('zoho_invoice_id', $mirror->id)->first();

        if ($mirror->trashed()) {
            if ($existing && ! $dryRun) {
                $existing->update(['status' => 'Cancelled', 'zoho_status' => 'deleted']);
                app(RegisterPaymentsImporter::class)->fillGap($existing);
                if ($existing->client_id && ! $this->bulk) {
                    app(ClientLedgerSync::class)->sync((int) $existing->client_id);
                }
            }

            return ['action' => $existing ? 'cancelled' : 'skipped', 'row' => $existing];
        }

        $clientId = $this->clientFor($mirror);
        $project = $mirror->project_id ? Project::find($mirror->project_id) : $this->projectForUin((string) $mirror->number, $clientId);
        $clientId ??= $project?->client_id;
        if (! $dryRun && $clientId && ! $mirror->client_id) {
            // Improves the Zoho list too (ZohoInvoice has no events that re-import here: quiet update).
            $mirror->forceFill(['client_id' => $clientId])->saveQuietly();
        }

        $action = $existing ? 'updated' : 'created';
        $row = $existing ?? $this->portalRowFor($mirror, $type);
        if (! $existing && $row) {
            $action = 'linked';
        }

        $attrs = $this->attributes($mirror, $type, $clientId, $project);
        if ($dryRun) {
            return ['action' => $action, 'row' => $row];
        }

        $previousClient = $row?->client_id;
        // A client's uploaded payment proof stays "Proof Submitted" until Zoho records the payment.
        if ($row && $row->payment_status === 'Proof Submitted' && ($attrs['payment_status'] ?? null) !== 'Confirmed') {
            unset($attrs['payment_status']);
        }
        if ($row) {
            // A portal-raised row keeps its own UIN/fees; it gains the Zoho link, status and balance.
            if ($row->source !== 'zoho' && $action === 'linked') {
                $attrs = array_intersect_key($attrs, array_flip(['zoho_invoice_id', 'zoho_number', 'zoho_status', 'balance_due', 'payment_status', 'due_date', 'total_bcy', 'exchange_rate']));
            }
            $row->update($attrs);
        } else {
            $row = PatentInvoiceIn::create($attrs + ['source' => 'zoho']);
        }

        // Payments with real dates (Zoho customer payments; any unrecorded part estimated) and
        // the client's ledger follow every change. Skipped during the bulk import, which does
        // both once at the end.
        if (! $this->bulk) {
            app(RegisterPaymentsImporter::class)->fillGap($row, $this->detail($mirror)['last_payment_date'] ?? null);
            foreach (array_unique(array_filter([$row->client_id, $previousClient])) as $client) {
                app(ClientLedgerSync::class)->sync((int) $client);
            }
        }

        return ['action' => $action, 'row' => $row];
    }

    /** Imports every mirrored record; returns counts (and totals) for the command's report. */
    public function importAll(bool $dryRun = false, ?callable $progress = null): array
    {
        $report = ['created' => 0, 'updated' => 0, 'linked' => 0, 'cancelled' => 0, 'skipped' => 0,
            'no_client' => 0, 'no_case' => 0, 'split_pending' => 0,
            'totals' => ['invoice' => 0.0, 'quote' => 0.0], 'payments' => 0, 'ledgers' => 0];
        $this->bulk = true;
        ZohoInvoice::withTrashed()->orderBy('id')->chunkById(200, function ($records) use (&$report, $dryRun, $progress) {
            foreach ($records as $mirror) {
                try {
                    $result = $dryRun ? $this->import($mirror, true) : DB::transaction(fn () => $this->import($mirror));
                } catch (\Throwable $e) {
                    Log::warning('Zoho register import failed', ['zoho_invoice_id' => $mirror->id, 'error' => $e->getMessage()]);
                    $report['skipped']++;

                    continue;
                }
                $report[$result['action']]++;
                if (! $mirror->trashed()) {
                    $report['totals'][$mirror->zoho_type === 'quote' ? 'quote' : 'invoice'] += (float) $mirror->total;
                    $this->tally = false;
                    $attrs = $this->attributes($mirror, $mirror->zoho_type === 'quote' ? 'quote' : 'invoice', $this->clientFor($mirror) ?? Project::whereKey($mirror->project_id)->value('client_id'), null);
                    $this->tally = true;
                    $report['no_client'] += $attrs['client_id'] ? 0 : 1;
                    $report['no_case'] += $attrs['project_id'] ? 0 : 1;
                    $report['split_pending'] += $attrs['amount_split_pending'] ? 1 : 0;
                }
                $progress && $progress();
            }
        });
        $this->bulk = false;
        if (! $dryRun) {
            // Payments with their dates, gaps for invoices paid without a payment record, then ledgers.
            $payments = app(RegisterPaymentsImporter::class);
            $report['payments'] = $payments->importAll();
            PatentInvoiceIn::where('source', 'zoho')->where('type', 'invoice')->with('zohoRecord')->chunkById(200, function ($rows) use ($payments) {
                foreach ($rows as $row) {
                    $payments->fillGap($row, $row->zohoRecord ? ($this->detail($row->zohoRecord)['last_payment_date'] ?? null) : null);
                }
            });
            $report['ledgers'] = app(ClientLedgerSync::class)->syncAll();
        } else {
            $report['payments'] = \App\Models\ZohoSnapshot::where('resource', 'customerpayments')->count();
        }
        arsort($this->unclassified);
        $report['unclassified_items'] = array_slice($this->unclassified, 0, 40, true);

        return $report;
    }

    /** The register fields for a mirrored Zoho record (amounts split from its snapshot detail). */
    private function attributes(ZohoInvoice $mirror, string $type, ?int $clientId, ?Project $project): array
    {
        $number = strtoupper(trim((string) $mirror->number));
        $uin = $this->parseUin($number);
        $detail = $this->detail($mirror);
        $split = $this->split($detail, (float) $mirror->total);
        $total = (float) $mirror->total;
        $balance = $mirror->balance !== null ? (float) $mirror->balance : null;
        $rate = isset($detail['exchange_rate']) && (float) $detail['exchange_rate'] > 0 ? (float) $detail['exchange_rate'] : 1.0;
        $zohoStatus = strtolower((string) $mirror->status);
        $client = $clientId ? Client::find($clientId) : null;
        $inventor = $project ? rescue(fn () => $project->inventors()->value('full_name'), null, false) : null;

        $attrs = [
            'type' => $type,
            'status' => $this->status($type, $zohoStatus),
            'zoho_invoice_id' => $mirror->id,
            'zoho_number' => $mirror->number,
            'zoho_status' => $mirror->status,
            'project_id' => $project?->id ?? $mirror->project_id,
            'client_id' => $clientId,
            'docket_number' => $project?->docket_number ?? $uin['docket'],
            'invoice_uin' => $mirror->number,
            'invoice_date' => $mirror->txn_date,
            'due_date' => $mirror->due_date,
            'client_code_prefix' => $uin['client_code'],
            'invention_number' => $uin['invention'],
            'patent_office_code' => $project?->patent_office_code ?? $uin['office'],
            'service_code' => $project?->service_code ?? $uin['service'],
            'invention_title' => $project ? ($project->invention_title ?: $project->project_name) : null,
            'client_name' => $client ? ($client->legal_name ?: $client->company_name) : ($detail['customer_name'] ?? null),
            // The invoice / quote form, filled from the case and client where they are known.
            'first_inventor_name' => $inventor,
            'patent_office_application_number' => $project?->application_number ?: $this->cleanCf($detail['cf_application_no'] ?? $mirror->application_no),
            'client_reference' => $client?->referred_by_code,
            'state_of_supply' => $client?->state ?: ($detail['place_of_supply_name'] ?? $detail['place_of_supply'] ?? null),
            'entity_status' => $client?->fee_entity_tier,
            'currency' => $mirror->currency ?: 'INR',
            'exchange_rate' => $rate,
            'total_bcy' => round($total * $rate, 2),
            'invoice_amount' => $total,
            'balance_due' => $balance,
            'patent_office_fees' => $split['office'],
            'service_fees' => $split['service'],
            'other_expenses' => $split['expenses'],
            'discount_amount' => $split['discount'],
            'igst_amount' => $split['igst'],
            'cgst_amount' => $split['cgst'],
            'sgst_amount' => $split['sgst'],
            'amount_split_pending' => $split['pending'],
        ];
        if ($type === 'invoice') {
            $attrs['payment_status'] = $this->paymentStatus($zohoStatus, $total, $balance);
        }

        return $attrs;
    }

    private function status(string $type, string $zohoStatus): string
    {
        if ($type === 'quote') {
            return match ($zohoStatus) {
                'draft' => 'Draft',
                'accepted', 'invoiced' => 'Accepted',
                'declined' => 'Rejected',
                'expired', 'void' => 'Cancelled',
                default => 'Sent',
            };
        }

        return match ($zohoStatus) {
            'draft' => 'Draft',
            'void' => 'Cancelled',
            default => 'Sent',
        };
    }

    private function paymentStatus(string $zohoStatus, float $total, ?float $balance): string
    {
        if ($zohoStatus === 'paid' || ($balance !== null && $balance <= 0.009 && $total > 0 && ! in_array($zohoStatus, ['draft', 'void'], true))) {
            return 'Confirmed';
        }
        if ($zohoStatus === 'partially_paid' || ($balance !== null && $balance > 0 && $balance < $total)) {
            return 'Partially Paid';
        }

        return 'Pending';
    }

    /**
     * The case a UIN belongs to, only when exactly one case fits: the docket equal to the UIN
     * without its "/N", else its first 12 characters (client + invention + office + service),
     * else the 9-character prefix with the same service code.
     */
    public function projectForUin(string $number, ?int $clientId = null): ?Project
    {
        $base = strtoupper(preg_replace('#/.*$#', '', trim($number)));
        if (strlen($base) < 9) {
            return null;
        }
        $unique = function ($query) use ($clientId) {
            $rows = $query->when($clientId, fn ($q) => $q->where('client_id', $clientId))->limit(2)->get();

            return $rows->count() === 1 ? $rows->first() : null;
        };
        $uin = $this->parseUin($base);

        return $unique(Project::whereRaw('UPPER(docket_number) = ?', [$base]))
            ?? (strlen($base) > 12 ? $unique(Project::whereRaw('UPPER(docket_number) = ?', [substr($base, 0, 12)])) : null)
            ?? ($uin['service'] ? $unique(Project::whereRaw('UPPER(SUBSTRING(docket_number, 1, 9)) = ?', [substr($base, 0, 9)])
                ->whereRaw('UPPER(service_code) = ?', [$uin['service']])) : null);
    }

    /** A case saved with this docket picks up the Zoho register rows whose UIN matches it. */
    public function linkProject(Project $project): int
    {
        $docket = strtoupper((string) $project->docket_number);
        if (strlen($docket) < 9) {
            return 0;
        }
        $linked = 0;
        $rows = PatentInvoiceIn::where('source', 'zoho')->whereNull('project_id')
            ->whereRaw('UPPER(invoice_uin) LIKE ?', [substr($docket, 0, 9).'%'])->with('zohoRecord')->get();
        foreach ($rows as $row) {
            if ($row->zohoRecord && $this->projectForUin((string) $row->invoice_uin, $row->client_id)?->id === $project->id) {
                $row->zohoRecord->forceFill(['project_id' => $project->id, 'match_source' => $row->zohoRecord->match_source ?: 'uin'])->saveQuietly();
                $this->import($row->zohoRecord->fresh());
                $linked++;
            }
        }

        return $linked;
    }

    private function cleanCf(mixed $value): ?string
    {
        $value = trim((string) $value, " '\t\n-");

        return $value === '' ? null : $value;
    }

    /** "B37M001INPRV647" → client code B37M, invention 001, office IN, service PRV, docket B37M001INPRV. */
    public function parseUin(string $number): array
    {
        $base = preg_replace('#/.*$#', '', $number);
        if (preg_match('/^([A-Z0-9]{4})([A-Z0-9]{3})([A-Z]{2})([A-Z0-9]{3})/', $base, $m)) {
            return ['client_code' => $m[1], 'invention' => $m[2], 'office' => $m[3], 'service' => $m[4], 'docket' => $m[1].$m[2].$m[3].$m[4]];
        }

        return ['client_code' => strlen($base) >= 4 ? substr($base, 0, 4) : null, 'invention' => null, 'office' => null, 'service' => null, 'docket' => null];
    }

    /** Client: the mirror's, the client linked to the Zoho customer, or the client code at the start of the number. */
    public function clientFor(ZohoInvoice $mirror): ?int
    {
        if ($mirror->client_id) {
            return (int) $mirror->client_id;
        }
        $this->clientsByContact ??= Client::whereNotNull('zoho_contact_id')->pluck('id', 'zoho_contact_id')->all();
        if ($mirror->zoho_contact_id && isset($this->clientsByContact[$mirror->zoho_contact_id])) {
            return (int) $this->clientsByContact[$mirror->zoho_contact_id];
        }
        $this->clientsByCode ??= Client::whereNotNull('client_code')->get(['id', 'client_code'])
            ->mapWithKeys(fn (Client $c) => [strtoupper($c->client_code) => $c->id])->all();
        $code = strtoupper(substr(trim((string) $mirror->number), 0, 4));

        return isset($this->clientsByCode[$code]) ? (int) $this->clientsByCode[$code] : null;
    }

    /** A portal row this Zoho record already belongs to: an exported quote, or the same UIN and type. */
    private function portalRowFor(ZohoInvoice $mirror, string $type): ?PatentInvoiceIn
    {
        if ($type === 'quote') {
            $export = ZohoQuoteExport::where('scope', $mirror->scope)->where('estimate_id', (string) $mirror->zoho_id)->where('source_type', 'in')->first();
            if ($export && ($row = PatentInvoiceIn::whereKey($export->source_id)->whereNull('zoho_invoice_id')->first())) {
                return $row;
            }
        }

        return PatentInvoiceIn::whereNull('zoho_invoice_id')->where('type', $type)
            ->whereRaw('UPPER(invoice_uin) = ?', [strtoupper(trim((string) $mirror->number))])->first();
    }

    private function detail(ZohoInvoice $mirror): array
    {
        $snapshot = ZohoSnapshot::where('scope', $mirror->scope)->where('resource', $mirror->zoho_type === 'quote' ? 'estimates' : 'invoices')
            ->where('remote_id', (string) $mirror->zoho_id)->first();
        $json = $snapshot?->detail;

        return $json ? (json_decode($json, true) ?: []) : [];
    }

    /**
     * Splits a Zoho record into Patent Office fees, service fees and expenses (by line-item
     * name) and IGST / CGST / SGST (by tax name). Without line items the total goes to
     * service fees and the row is flagged until the detail arrives.
     */
    public function split(array $detail, float $total): array
    {
        $out = ['office' => 0.0, 'service' => 0.0, 'expenses' => 0.0, 'discount' => 0.0, 'igst' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'pending' => false];
        $items = $detail['line_items'] ?? [];
        if (! is_array($items) || $items === []) {
            $out['service'] = $total;
            $out['pending'] = true;

            return $out;
        }
        foreach ($items as $item) {
            $amount = (float) ($item['item_total'] ?? (($item['rate'] ?? 0) * ($item['quantity'] ?? 1)));
            // The item code is the name; a few lines leave the name blank and put the code in the description.
            $code = strtoupper(trim((string) (($item['name'] ?? '') !== '' ? $item['name'] : ($item['description'] ?? ''))));
            $text = strtolower(trim(($item['name'] ?? '').' '.($item['description'] ?? '').' '.($item['account_name'] ?? '')));
            if (in_array($code, self::DISCOUNT_CODES, true) || ($amount < 0 && str_contains($text, 'discount'))) {
                $out['discount'] += abs($amount);
            } elseif (in_array($code, self::EXPENSE_CODES, true) || $this->matches($text, self::EXPENSE_WORDS)) {
                $out['expenses'] += $amount;
            } elseif (in_array($code, self::OFFICE_FEE_CODES, true) || $this->matches($text, self::OFFICE_FEE_WORDS)) {
                $out['office'] += $amount;
            } else {
                $out['service'] += $amount;
                $name = trim((string) ($item['name'] ?? '')) ?: mb_substr(trim((string) ($item['description'] ?? '')), 0, 60);
                if ($name !== '' && $this->tally) {
                    $this->unclassified[$name] = ($this->unclassified[$name] ?? 0) + 1;
                }
            }
        }
        foreach (($detail['taxes'] ?? []) as $tax) {
            $name = strtoupper((string) ($tax['tax_name'] ?? ''));
            $amount = (float) ($tax['tax_amount'] ?? 0);
            if (str_contains($name, 'IGST')) {
                $out['igst'] += $amount;
            } elseif (str_contains($name, 'CGST')) {
                $out['cgst'] += $amount;
            } elseif (str_contains($name, 'SGST') || str_contains($name, 'UTGST')) {
                $out['sgst'] += $amount;
            } elseif (str_contains($name, 'GST')) {
                // A GST group (e.g. GST18) is CGST + SGST in equal halves.
                $out['cgst'] += $amount / 2;
                $out['sgst'] += $amount / 2;
            }
        }
        $out['discount'] += (float) ($detail['discount_total'] ?? 0);
        foreach (['office', 'service', 'expenses', 'discount', 'igst', 'cgst', 'sgst'] as $key) {
            $out[$key] = round($out[$key], 2);
        }

        return $out;
    }

    private function matches(string $text, array $words): bool
    {
        foreach ($words as $word) {
            if (str_contains($text, $word)) {
                return true;
            }
        }

        return false;
    }
}
