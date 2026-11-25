<?php

namespace App\Services;

use App\Models\AdvanceQuoteLink;
use App\Models\Client;
use App\Models\PatentInvoiceIn;
use App\Models\RegisterPayment;
use App\Models\User;
use App\Models\ZohoInvoice;
use App\Models\ZohoSnapshot;
use App\Support\DataAccess;
use Illuminate\Support\Collection;

/**
 * Payments received, one row per customer payment (Zoho Books, plus payments marked in the
 * portal and not yet in Zoho): the cash received, which invoices it settled, the TDS the client
 * withheld on it, and any unused part (an advance, optionally earmarked for a proforma quote).
 * Within the user's finance scope: staff who see part of the finance see only payments touching
 * their invoices, and only those applications.
 */
class PaymentsReceived
{
    /** @return Collection<int, array<string, mixed>> newest first */
    public function rows(User $user): Collection
    {
        $all = DataAccess::seesAllFinance($user);
        $allowed = $all ? null : array_flip(app(FinanceFigures::class)->registerInvoices($user)->pluck('id')->all());
        $allowedClients = $all ? null : array_flip(app(FinanceFigures::class)->registerInvoices($user)->whereNotNull('client_id')->distinct()->pluck('client_id')->all());

        $clients = Client::get(['id', 'client_code', 'legal_name', 'company_name', 'zoho_contact_id'])->keyBy('id');
        $byContact = $clients->filter(fn ($c) => $c->zoho_contact_id)->mapWithKeys(fn ($c) => [(string) $c->zoho_contact_id => $c->id])->all()
            + ZohoInvoice::whereNotNull('zoho_contact_id')->whereNotNull('client_id')->distinct()->pluck('client_id', 'zoho_contact_id')->all();
        $register = PatentInvoiceIn::query()->join('zoho_invoices', 'zoho_invoices.id', '=', 'patent_invoices_in.zoho_invoice_id')
            ->where('zoho_invoices.zoho_type', 'invoice')
            ->get(['patent_invoices_in.id', 'patent_invoices_in.invoice_uin', 'patent_invoices_in.client_id', 'zoho_invoices.zoho_id'])
            ->keyBy(fn ($r) => (string) $r->zoho_id);
        $links = AdvanceQuoteLink::with('quote:id,invoice_uin,zoho_number,zoho_status,status')->get()->groupBy('zoho_payment_id');

        $rows = collect();
        foreach (ZohoSnapshot::where('resource', 'customerpayments')->get(['remote_id', 'detail', 'summary']) as $snapshot) {
            $p = json_decode((string) ($snapshot->detail ?: $snapshot->summary), true) ?: [];
            if (empty($p['date'])) {
                continue;
            }
            $rate = (float) ($p['exchange_rate'] ?? 1) ?: 1.0;
            $applied = [];
            $clientId = $byContact[(string) ($p['customer_id'] ?? '')] ?? null;
            foreach (($p['invoices'] ?? []) as $a) {
                $inv = $register[(string) ($a['invoice_id'] ?? '')] ?? null;
                $clientId ??= $inv?->client_id;
                if ($allowed !== null && (! $inv || ! isset($allowed[$inv->id]))) {
                    continue;
                }
                $applied[] = ['invoice_id' => $inv?->id, 'invoice' => $inv?->invoice_uin ?? ($a['invoice_number'] ?? '—'),
                    'amount' => round((float) ($a['amount_applied'] ?? 0) * $rate, 2), 'tds' => round((float) ($a['tax_amount_withheld'] ?? 0) * $rate, 2)];
            }
            $unused = round((float) ($p['unused_amount'] ?? 0) * $rate, 2);
            if ($allowed !== null && ! $applied && ! ($unused > 0 && $clientId && isset($allowedClients[$clientId]))) {
                continue;
            }
            $id = (string) $snapshot->remote_id;
            $rows->push($this->row($id, $p['payment_number'] ?? null, $p['date'], $clientId ? $clients[$clientId] ?? null : null, $p['customer_name'] ?? null,
                $p['payment_mode'] ?? null, $p['reference_number'] ?? null, round((float) ($p['amount'] ?? 0) * $rate, 2), $p['currency_code'] ?? 'INR',
                $applied, $allowed === null || ($clientId && isset($allowedClients[$clientId])) ? $unused : 0.0, $links[$id] ?? collect(), 'zoho'));
        }

        // Marked paid in the portal and not yet recorded in Zoho.
        $portal = RegisterPayment::cash()->where('source', 'portal')->where('zoho_payment_id', 'like', 'portal:%')
            ->with('invoice:id,invoice_uin,client_id')->get();
        foreach ($portal as $p) {
            if ($allowed !== null && ! isset($allowed[$p->patent_invoice_in_id])) {
                continue;
            }
            $rows->push($this->row($p->zoho_payment_id, 'Marked in portal', $p->payment_date?->toDateString(), $clients[$p->invoice?->client_id] ?? null, null,
                $p->payment_mode, $p->reference, (float) $p->amount_bcy, 'INR',
                [['invoice_id' => $p->patent_invoice_in_id, 'invoice' => $p->invoice?->invoice_uin, 'amount' => (float) $p->amount_bcy, 'tds' => 0.0]], 0.0, collect(), 'portal'));
        }

        return $rows->sortByDesc(fn ($r) => $r['date'].'|'.$r['number'])->values();
    }

    private function row(string $id, ?string $number, ?string $date, ?Client $client, ?string $customer, ?string $mode, ?string $reference,
        float $amount, string $currency, array $applied, float $unused, Collection $links, string $source): array
    {
        $linked = round((float) $links->sum('amount'), 2);

        return [
            'id' => $id, 'number' => $number, 'date' => $date, 'source' => $source,
            'client' => $client ? ['id' => $client->id, 'code' => $client->client_code, 'name' => $client->legal_name ?: $client->company_name] : null,
            'customer_name' => $customer, 'mode' => $mode, 'reference' => $reference, 'currency' => $currency,
            'amount' => $amount, 'applied' => $applied,
            'applied_total' => round(array_sum(array_column($applied, 'amount')), 2),
            'tds' => round(array_sum(array_column($applied, 'tds')), 2),
            'unused' => $unused,
            'links' => $links->map(fn (AdvanceQuoteLink $l) => ['id' => $l->id, 'quote_id' => $l->patent_invoice_in_id,
                'quote' => $l->quote?->invoice_uin ?: $l->quote?->zoho_number, 'amount' => (float) $l->amount, 'note' => $l->note,
                'invoiced' => strtolower((string) $l->quote?->zoho_status) === 'invoiced'])->values()->all(),
            'unlinked' => round(max(0, $unused - $linked), 2),
        ];
    }

    /**
     * @param  array{search?: string|null, client_id?: int|string|null, from?: string|null, to?: string|null, unused?: bool}  $filters
     */
    public function filter(Collection $rows, array $filters): Collection
    {
        $q = mb_strtolower(trim((string) ($filters['search'] ?? '')));

        return $rows->filter(function ($r) use ($filters, $q) {
            if (! empty($filters['client_id']) && (int) ($r['client']['id'] ?? 0) !== (int) $filters['client_id']) {
                return false;
            }
            if (! empty($filters['from']) && $r['date'] < $filters['from']) {
                return false;
            }
            if (! empty($filters['to']) && $r['date'] > $filters['to']) {
                return false;
            }
            if (! empty($filters['unused']) && $r['unused'] <= 0) {
                return false;
            }
            if ($q !== '') {
                $hay = mb_strtolower(implode(' ', [$r['number'], $r['reference'], $r['customer_name'], $r['client']['code'] ?? '', $r['client']['name'] ?? '',
                    implode(' ', array_column($r['applied'], 'invoice'))]));

                return str_contains($hay, $q);
            }

            return true;
        })->values();
    }

    /** The unused amount of one Zoho customer payment (INR), and its client. */
    public function advance(string $paymentId): ?array
    {
        $snapshot = ZohoSnapshot::where('resource', 'customerpayments')->where('remote_id', $paymentId)->first();
        if (! $snapshot) {
            return null;
        }
        $p = json_decode((string) ($snapshot->detail ?: $snapshot->summary), true) ?: [];
        $clientId = app(RegisterPaymentsImporter::class)->clientsFor($snapshot, [])[0]
            ?? RegisterPayment::where('zoho_payment_id', $paymentId)->whereNotNull('client_id')->value('client_id');

        return ['unused' => round((float) ($p['unused_amount'] ?? 0) * ((float) ($p['exchange_rate'] ?? 1) ?: 1.0), 2),
            'number' => $p['payment_number'] ?? null, 'client_id' => $clientId];
    }
}
