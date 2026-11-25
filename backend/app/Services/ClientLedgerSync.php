<?php

namespace App\Services;

use App\Models\ClientLedger;
use App\Models\PatentInvoiceIn;
use App\Models\RegisterPayment;
use App\Models\ZohoInvoice;
use App\Models\ZohoOpeningBalance;
use App\Models\ZohoSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * A client's ledger, rebuilt from the register, the way Zoho's customer statement reads:
 * each billed invoice is a debit; payments, TDS withheld by the client (type "TDS", also in
 * the TDS column: not cash until deposited) and credit notes are credits; money received but
 * not yet applied to any invoice is an "Advance" credit (Zoho's unused credit); the Zoho
 * opening balance (owed before the books moved to Zoho) is an opening debit. A minus balance
 * is credit the client holds with us. Lines written by other flows (portal invoices/payments)
 * are kept. The running balance is recomputed in date order every time.
 */
class ClientLedgerSync
{
    public function sync(int $clientId): void
    {
        DB::transaction(function () use ($clientId) {
            $wanted = [];

            foreach (ZohoOpeningBalance::where('client_id', $clientId)->get() as $opening) {
                if ((float) $opening->amount == 0.0) {
                    continue;
                }
                $wanted["zoho-open:{$opening->id}"] = [
                    'transaction_date' => $opening->balance_date, 'document_type' => 'Opening Balance',
                    'document_reference' => 'Opening balance (Zoho)', 'debit' => (float) $opening->amount, 'credit' => 0,
                    'notes' => $opening->customer_name,
                ];
                $settled = round((float) $opening->amount - (float) $opening->balance, 2);
                if ($settled > 0) {
                    $wanted["zoho-open-paid:{$opening->id}"] = [
                        'transaction_date' => $opening->balance_date, 'document_type' => 'Payment',
                        'document_reference' => 'Opening balance settled (Zoho)', 'debit' => 0, 'credit' => $settled, 'notes' => null,
                    ];
                }
            }

            $invoices = PatentInvoiceIn::where('client_id', $clientId)->where('type', 'invoice')
                ->whereNotIn('status', ['Draft', 'Cancelled'])->whereNotNull('invoice_date')->get();
            foreach ($invoices as $invoice) {
                $wanted["reg-inv:{$invoice->id}"] = [
                    'transaction_date' => $invoice->invoice_date, 'document_type' => 'Invoice',
                    'document_reference' => $invoice->invoice_uin ?: $invoice->zoho_number ?: "INV-{$invoice->id}",
                    'debit' => (float) ($invoice->total_bcy ?? $invoice->invoice_amount), 'credit' => 0,
                    'notes' => $invoice->invention_title,
                ];
            }
            // Balances waived off in the portal (approved): a credit, like a credit note.
            foreach ($invoices->filter(fn ($i) => (float) $i->waived_amount > 0) as $invoice) {
                $wanted["reg-waive:{$invoice->id}"] = [
                    'transaction_date' => $invoice->settled_at ?? now(), 'document_type' => 'Waiver',
                    'document_reference' => 'Waived → '.($invoice->invoice_uin ?: "INV-{$invoice->id}"),
                    'debit' => 0, 'credit' => round((float) $invoice->waived_amount * ((float) ($invoice->exchange_rate ?? 1) ?: 1), 2),
                    'notes' => $invoice->waiver_reason,
                ];
            }
            $payments = RegisterPayment::whereIn('patent_invoice_in_id', $invoices->pluck('id'))->with('invoice:id,invoice_uin')->get();
            foreach ($payments as $payment) {
                $invoiceUin = $payment->invoice?->invoice_uin ?? '';
                $line = match ($payment->kind) {
                    RegisterPayment::TDS => ['document_type' => 'TDS', 'document_reference' => trim('TDS · '.($payment->payment_number ?: '').' → '.$invoiceUin),
                        'tds' => (float) $payment->amount_bcy, 'notes' => 'Tax withheld by the client (not cash received)'],
                    RegisterPayment::CREDIT_NOTE => ['document_type' => 'Credit note', 'document_reference' => trim(($payment->payment_number ?: 'Credit note').' → '.$invoiceUin),
                        'tds' => 0, 'notes' => 'Credit note applied'],
                    default => ['document_type' => 'Payment', 'document_reference' => trim(($payment->payment_number ?: 'Payment').' → '.$invoiceUin), 'tds' => 0,
                        'notes' => $payment->estimated ? 'Paid per Zoho (date estimated)' : trim(($payment->source === 'portal' ? 'Marked paid in portal · ' : '').($payment->payment_mode ?? ''), ' ·')],
                };
                $wanted["reg-pay:{$payment->id}"] = $line + [
                    'transaction_date' => $payment->payment_date, 'debit' => 0, 'credit' => (float) $payment->amount_bcy,
                ];
            }

            // Foreign-currency invoices: the INR received differs from the invoice's INR value at its
            // own rate. Zoho books that difference to exchange gain/loss, not to the customer, so it
            // gets its own line and the client's INR balance matches Zoho.
            foreach ($invoices->filter(fn ($i) => strtoupper((string) ($i->currency ?: 'INR')) !== 'INR') as $invoice) {
                $mine = $payments->where('patent_invoice_in_id', $invoice->id);
                if ($mine->isEmpty()) {
                    continue;
                }
                $rate = (float) ($invoice->exchange_rate ?? 1) ?: 1.0;
                $diff = round((float) $mine->sum('amount_bcy') - (float) $mine->sum('amount') * $rate, 2);
                if (abs($diff) >= 1) {
                    $wanted["fx:{$invoice->id}"] = [
                        'transaction_date' => $mine->max('payment_date'), 'document_type' => 'Exchange difference',
                        'document_reference' => 'FX · '.($invoice->invoice_uin ?: "INV-{$invoice->id}"),
                        'debit' => $diff > 0 ? $diff : 0, 'credit' => $diff < 0 ? -$diff : 0, 'tds' => 0,
                        'notes' => ($diff > 0 ? 'Exchange gain' : 'Exchange loss').' ('.$invoice->currency.' at '.rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.').' on the invoice)',
                    ];
                }
            }

            // Money received but not applied to any invoice yet (Zoho "unused credit"): an advance
            // the client holds with us, and unapplied credit-note balances.
            foreach ($this->unusedCredits($clientId) as $key => $line) {
                $wanted[$key] = $line;
            }

            // Drop register lines that no longer apply, upsert the rest.
            ClientLedger::where('client_id', $clientId)->whereNotNull('source_key')
                ->whereNotIn('source_key', array_keys($wanted) ?: [''])->delete();
            foreach ($wanted as $key => $line) {
                ClientLedger::updateOrCreate(['source_key' => $key], $line + ['client_id' => $clientId, 'balance' => 0, 'tds' => 0]);
            }

            $this->rebalance($clientId);
        });
    }

    /**
     * Zoho customer payments and credit notes of this client with an unapplied amount, as
     * ledger lines keyed "zoho-unused:<id>" / "zoho-cn-unused:<id>". Zoho's unused amount is the
     * current one: once it is applied to an invoice, that application arrives as a payment line
     * and the advance shrinks, so nothing is counted twice.
     *
     * @return array<string, array<string, mixed>>
     */
    public function unusedCredits(int $clientId): array
    {
        $contacts = $this->zohoContactIds($clientId);
        $linkedPayments = RegisterPayment::where('client_id', $clientId)->whereNotNull('zoho_payment_id')->pluck('zoho_payment_id')
            ->map(fn ($id) => preg_replace('/^(tds|cn):/', '', (string) $id))->unique()->values()->all();
        $lines = [];
        foreach (['customerpayments', 'creditnotes'] as $resource) {
            // By the plain customer column (the snapshot bodies are encrypted), or through the register.
            $snapshots = ZohoSnapshot::where('resource', $resource)->where(function ($q) use ($contacts, $linkedPayments) {
                $q->whereIn('remote_id', $linkedPayments ?: [''])->orWhereIn('zoho_customer_id', $contacts ?: ['']);
            })->get();
            foreach ($snapshots as $snapshot) {
                $doc = json_decode((string) ($snapshot->detail ?: $snapshot->summary), true) ?: [];
                $customer = (string) ($doc['customer_id'] ?? '');
                if ($customer !== '' && ! in_array($customer, $contacts, true) && $this->claimedByAnotherClient($customer, $clientId)) {
                    continue;
                }
                $rate = (float) ($doc['exchange_rate'] ?? 1) ?: 1.0;
                if ($resource === 'customerpayments') {
                    $unused = (float) ($doc['unused_amount'] ?? 0);
                    if ($unused <= 0.009 || empty($doc['date'])) {
                        continue;
                    }
                    $lines["zoho-unused:{$snapshot->remote_id}"] = [
                        'transaction_date' => $doc['date'], 'document_type' => 'Advance',
                        'document_reference' => trim(($doc['payment_number'] ?? 'Payment').' · unused credit'),
                        'debit' => 0, 'credit' => round($unused * $rate, 2), 'tds' => 0,
                        'notes' => 'Received, not yet applied to an invoice (advance)',
                    ];
                } else {
                    $balance = (float) ($doc['balance'] ?? 0);
                    if ($balance <= 0.009 || empty($doc['date']) || strtolower((string) ($doc['status'] ?? '')) === 'void') {
                        continue;
                    }
                    $lines["zoho-cn-unused:{$snapshot->remote_id}"] = [
                        'transaction_date' => $doc['date'], 'document_type' => 'Credit note',
                        'document_reference' => trim(($doc['creditnote_number'] ?? 'Credit note').' · unused credit'),
                        'debit' => 0, 'credit' => round($balance * $rate, 2), 'tds' => 0,
                        'notes' => 'Credit note not yet applied to an invoice',
                    ];
                }
            }
        }

        return $lines;
    }

    /** The client's Zoho customer ids: its linked contact, and the customer on its Zoho invoices. */
    private function zohoContactIds(int $clientId): array
    {
        return collect([\App\Models\Client::whereKey($clientId)->value('zoho_contact_id')])
            ->merge(ZohoInvoice::withTrashed()->where('client_id', $clientId)->whereNotNull('zoho_contact_id')->distinct()->pluck('zoho_contact_id'))
            ->filter()->map(fn ($id) => (string) $id)->unique()->values()->all();
    }

    private function claimedByAnotherClient(string $customer, int $clientId): bool
    {
        return \App\Models\Client::where('zoho_contact_id', $customer)->whereKeyNot($clientId)->exists()
            || ZohoInvoice::withTrashed()->where('zoho_contact_id', $customer)->whereNotNull('client_id')->where('client_id', '!=', $clientId)->exists();
    }

    /** Running balance in date order (debits add, credits subtract). */
    public function rebalance(int $clientId): void
    {
        $balance = 0.0;
        foreach (ClientLedger::where('client_id', $clientId)->orderBy('transaction_date')->orderByRaw('CASE WHEN debit > 0 THEN 0 ELSE 1 END')->orderBy('id')->get() as $line) {
            $balance = round($balance + (float) $line->debit - (float) $line->credit, 2);
            if ((float) $line->balance !== $balance) {
                $line->update(['balance' => $balance]);
            }
        }
    }

    public function syncAll(): int
    {
        $ids = PatentInvoiceIn::whereNotNull('client_id')->distinct()->pluck('client_id')
            ->merge(ZohoOpeningBalance::whereNotNull('client_id')->pluck('client_id'))
            ->merge(\App\Models\Client::whereNotNull('zoho_contact_id')->pluck('id'))->unique();
        foreach ($ids as $id) {
            $this->sync((int) $id);
        }

        return $ids->count();
    }
}
