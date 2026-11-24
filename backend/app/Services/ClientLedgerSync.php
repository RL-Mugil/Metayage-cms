<?php

namespace App\Services;

use App\Models\ClientLedger;
use App\Models\PatentInvoiceIn;
use App\Models\RegisterPayment;
use App\Models\ZohoOpeningBalance;
use Illuminate\Support\Facades\DB;

/**
 * A client's ledger, rebuilt from the register: each billed invoice is a debit, each payment
 * a credit, and the Zoho opening balance (owed before the books moved to Zoho) an opening
 * debit. Lines written by other flows (portal invoices/payments) are kept. The running
 * balance is recomputed in date order every time.
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
                $wanted["reg-pay:{$payment->id}"] = [
                    'transaction_date' => $payment->payment_date, 'document_type' => 'Payment',
                    'document_reference' => trim(($payment->payment_number ?: 'Payment').' → '.($payment->invoice?->invoice_uin ?? '')),
                    'debit' => 0, 'credit' => (float) $payment->amount_bcy,
                    'notes' => $payment->estimated ? 'Paid per Zoho (date estimated)' : trim(($payment->source === 'portal' ? 'Marked paid in portal · ' : '').($payment->payment_mode ?? ''), ' ·'),
                ];
            }

            // Drop register lines that no longer apply, upsert the rest.
            ClientLedger::where('client_id', $clientId)->whereNotNull('source_key')
                ->whereNotIn('source_key', array_keys($wanted) ?: [''])->delete();
            foreach ($wanted as $key => $line) {
                ClientLedger::updateOrCreate(['source_key' => $key], $line + ['client_id' => $clientId, 'balance' => 0]);
            }

            $this->rebalance($clientId);
        });
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
            ->merge(ZohoOpeningBalance::whereNotNull('client_id')->pluck('client_id'))->unique();
        foreach ($ids as $id) {
            $this->sync((int) $id);
        }

        return $ids->count();
    }
}
