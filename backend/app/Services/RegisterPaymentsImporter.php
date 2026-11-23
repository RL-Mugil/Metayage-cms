<?php

namespace App\Services;

use App\Models\PatentInvoiceIn;
use App\Models\RegisterPayment;
use App\Models\ZohoInvoice;
use App\Models\ZohoSnapshot;
use Carbon\Carbon;

/**
 * Payments against register invoices, with their real dates, from Zoho Books customer
 * payments (each lists the invoices it was applied to). An invoice Zoho shows as (partly)
 * paid without a payment record gets one estimated line dated Zoho's last payment date, so
 * collections are never missing; it is replaced as soon as the real payment arrives.
 */
class RegisterPaymentsImporter
{
    /** Imports one Zoho customer payment snapshot; returns the register invoice ids it touched. */
    public function importPayment(ZohoSnapshot $snapshot): array
    {
        $paymentId = (string) $snapshot->remote_id;
        $touched = RegisterPayment::where('zoho_payment_id', $paymentId)->pluck('patent_invoice_in_id')->all();

        if ($snapshot->trashed()) {
            RegisterPayment::where('zoho_payment_id', $paymentId)->delete();
        } else {
            $payment = json_decode((string) ($snapshot->detail ?: $snapshot->summary), true) ?: [];
            $date = $payment['date'] ?? null;
            $rate = (float) ($payment['exchange_rate'] ?? 1) ?: 1.0;
            $keep = [];
            foreach (($payment['invoices'] ?? []) as $applied) {
                $amount = (float) ($applied['amount_applied'] ?? 0);
                $mirrorId = ZohoInvoice::withTrashed()->where('scope', $snapshot->scope)->where('zoho_type', 'invoice')
                    ->where('zoho_id', (string) ($applied['invoice_id'] ?? ''))->value('id');
                $row = $mirrorId ? PatentInvoiceIn::where('zoho_invoice_id', $mirrorId)->first() : null;
                if (! $row || ! $date || $amount <= 0) {
                    continue;
                }
                RegisterPayment::updateOrCreate(
                    ['patent_invoice_in_id' => $row->id, 'zoho_payment_id' => $paymentId],
                    [
                        'client_id' => $row->client_id, 'payment_number' => $payment['payment_number'] ?? null,
                        'payment_date' => $date, 'amount' => $amount, 'currency' => $payment['currency_code'] ?? $row->currency ?? 'INR',
                        'exchange_rate' => $rate, 'amount_bcy' => round($amount * $rate, 2),
                        'payment_mode' => $payment['payment_mode'] ?? null, 'reference' => $payment['reference_number'] ?? null,
                        'estimated' => false,
                    ]
                );
                $keep[] = $row->id;
                $touched[] = $row->id;
            }
            // Applications removed in Zoho (payment re-applied elsewhere).
            RegisterPayment::where('zoho_payment_id', $paymentId)->whereNotIn('patent_invoice_in_id', $keep ?: [0])->delete();
        }

        $touched = array_values(array_unique($touched));
        foreach (PatentInvoiceIn::whereIn('id', $touched)->get() as $row) {
            $this->fillGap($row);
        }

        return $touched;
    }

    /**
     * Keeps the invoice's payments equal to what Zoho says was paid (total − balance): an
     * estimated line covers any part with no payment record yet, dated Zoho's last payment date.
     */
    public function fillGap(PatentInvoiceIn $row, ?string $lastPaymentDate = null): void
    {
        $key = 'balance:'.$row->id;
        if ($row->type !== 'invoice' || in_array($row->status, ['Draft', 'Cancelled'], true) || $row->balance_due === null) {
            RegisterPayment::where('patent_invoice_in_id', $row->id)->where('zoho_payment_id', $key)->delete();

            return;
        }
        $paid = max(0, (float) $row->invoice_amount - (float) $row->balance_due);
        $recorded = (float) RegisterPayment::where('patent_invoice_in_id', $row->id)->where('zoho_payment_id', '!=', $key)->sum('amount');
        $gap = round($paid - $recorded, 2);
        if ($gap <= 0.009) {
            RegisterPayment::where('patent_invoice_in_id', $row->id)->where('zoho_payment_id', $key)->delete();

            return;
        }
        $existing = RegisterPayment::where('patent_invoice_in_id', $row->id)->where('zoho_payment_id', $key)->first();
        $date = $lastPaymentDate ?: $existing?->payment_date?->toDateString() ?: ($row->invoice_date?->toDateString() ?? now()->toDateString());
        $rate = (float) ($row->exchange_rate ?? 1) ?: 1.0;
        RegisterPayment::updateOrCreate(
            ['patent_invoice_in_id' => $row->id, 'zoho_payment_id' => $key],
            ['client_id' => $row->client_id, 'payment_date' => Carbon::parse($date)->toDateString(), 'amount' => $gap,
                'currency' => $row->currency ?: 'INR', 'exchange_rate' => $rate, 'amount_bcy' => round($gap * $rate, 2),
                'payment_mode' => null, 'reference' => 'Paid per Zoho (no payment record)', 'estimated' => true]
        );
    }

    /** Every mirrored customer payment, then the gaps (used by finance:import-zoho). */
    public function importAll(): int
    {
        $count = 0;
        ZohoSnapshot::withTrashed()->where('resource', 'customerpayments')->orderBy('id')->chunkById(200, function ($snapshots) use (&$count) {
            foreach ($snapshots as $snapshot) {
                $this->importPayment($snapshot);
                $count++;
            }
        });

        return $count;
    }
}
