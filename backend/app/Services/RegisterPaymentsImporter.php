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
    /**
     * Imports one Zoho customer payment snapshot; returns the register invoice ids it touched.
     * Each invoice it was applied to gets a cash line, plus a TDS line when the client withheld
     * tax on it (Zoho "Tax Withheld"): TDS settles the invoice but is not money received.
     */
    public function importPayment(ZohoSnapshot $snapshot): array
    {
        $paymentId = (string) $snapshot->remote_id;
        $keys = [$paymentId, "tds:{$paymentId}"];
        $touched = RegisterPayment::whereIn('zoho_payment_id', $keys)->pluck('patent_invoice_in_id')->all();

        if ($snapshot->trashed()) {
            RegisterPayment::whereIn('zoho_payment_id', $keys)->delete();
        } else {
            $payment = json_decode((string) ($snapshot->detail ?: $snapshot->summary), true) ?: [];
            $date = $payment['date'] ?? null;
            $rate = (float) ($payment['exchange_rate'] ?? 1) ?: 1.0;
            $keep = [];
            foreach (($payment['invoices'] ?? []) as $applied) {
                $amount = (float) ($applied['amount_applied'] ?? 0);
                $tds = (float) ($applied['tax_amount_withheld'] ?? 0);
                $row = $this->registerRow($snapshot->scope, (string) ($applied['invoice_id'] ?? ''));
                if (! $row || ! $date || ($amount <= 0 && $tds <= 0)) {
                    continue;
                }
                $common = [
                    'client_id' => $row->client_id, 'payment_number' => $payment['payment_number'] ?? null,
                    'payment_date' => $date, 'currency' => $payment['currency_code'] ?? $row->currency ?? 'INR',
                    'exchange_rate' => $rate, 'payment_mode' => $payment['payment_mode'] ?? null, 'estimated' => false,
                ];
                if ($amount > 0) {
                    // A payment marked in the portal and then recorded in Zoho (by hand, or a write-back
                    // whose reply was lost) is the same money: adopt the portal line instead of adding one.
                    if (! RegisterPayment::where('patent_invoice_in_id', $row->id)->where('zoho_payment_id', $paymentId)->exists()) {
                        $portal = RegisterPayment::where('patent_invoice_in_id', $row->id)->where('source', 'portal')
                            ->where('zoho_payment_id', 'like', 'portal:%')->where('amount', $amount)
                            ->whereBetween('payment_date', [Carbon::parse($date)->subDays(7)->toDateString(), Carbon::parse($date)->addDays(7)->toDateString()])
                            ->first();
                        $portal?->update(['zoho_payment_id' => $paymentId]);
                    }
                    RegisterPayment::updateOrCreate(
                        ['patent_invoice_in_id' => $row->id, 'zoho_payment_id' => $paymentId],
                        $common + ['amount' => $amount, 'amount_bcy' => round($amount * $rate, 2),
                            'reference' => $payment['reference_number'] ?? null, 'kind' => RegisterPayment::CASH]
                    );
                    $keep[] = "{$row->id}|{$paymentId}";
                }
                if ($tds > 0) {
                    RegisterPayment::updateOrCreate(
                        ['patent_invoice_in_id' => $row->id, 'zoho_payment_id' => "tds:{$paymentId}"],
                        $common + ['amount' => $tds, 'amount_bcy' => round($tds * $rate, 2),
                            'reference' => 'TDS withheld', 'kind' => RegisterPayment::TDS]
                    );
                    $keep[] = "{$row->id}|tds:{$paymentId}";
                }
                $touched[] = $row->id;
            }
            // Applications removed in Zoho (payment re-applied elsewhere, TDS removed).
            RegisterPayment::whereIn('zoho_payment_id', $keys)->get()
                ->reject(fn (RegisterPayment $p) => in_array("{$p->patent_invoice_in_id}|{$p->zoho_payment_id}", $keep, true))
                ->each->delete();
        }

        return $this->afterImport($touched);
    }

    /**
     * Imports one Zoho credit note: each invoice it was credited to gets a credit-note line, so
     * the credit is not estimated as a payment. A void or deleted credit note removes them.
     */
    public function importCreditNote(ZohoSnapshot $snapshot): array
    {
        $key = 'cn:'.$snapshot->remote_id;
        $touched = RegisterPayment::where('zoho_payment_id', $key)->pluck('patent_invoice_in_id')->all();
        $note = json_decode((string) ($snapshot->detail ?: $snapshot->summary), true) ?: [];
        $keep = [];
        if (! $snapshot->trashed() && strtolower((string) ($note['status'] ?? '')) !== 'void') {
            $rate = (float) ($note['exchange_rate'] ?? 1) ?: 1.0;
            foreach (($note['invoices_credited'] ?? []) as $credited) {
                $amount = (float) ($credited['amount_credited'] ?? 0);
                $row = $this->registerRow($snapshot->scope, (string) ($credited['invoice_id'] ?? ''));
                $date = $credited['date'] ?? $note['date'] ?? null;
                if (! $row || ! $date || $amount <= 0) {
                    continue;
                }
                RegisterPayment::updateOrCreate(
                    ['patent_invoice_in_id' => $row->id, 'zoho_payment_id' => $key],
                    ['client_id' => $row->client_id, 'payment_number' => $note['creditnote_number'] ?? null, 'payment_date' => $date,
                        'amount' => $amount, 'currency' => $note['currency_code'] ?? $row->currency ?? 'INR', 'exchange_rate' => $rate,
                        'amount_bcy' => round($amount * $rate, 2), 'payment_mode' => null, 'reference' => 'Credit note',
                        'estimated' => false, 'kind' => RegisterPayment::CREDIT_NOTE]
                );
                $keep[] = $row->id;
                $touched[] = $row->id;
            }
        }
        RegisterPayment::where('zoho_payment_id', $key)->whereNotIn('patent_invoice_in_id', $keep ?: [0])->delete();

        return $this->afterImport($touched);
    }

    /**
     * The clients whose ledger a Zoho payment or credit note affects: those of the invoices it
     * touched, and the client linked to its Zoho customer (an unapplied advance touches none).
     *
     * @param  array<int, int>  $touched
     * @return array<int, int>
     */
    public function clientsFor(ZohoSnapshot $snapshot, array $touched): array
    {
        $ids = PatentInvoiceIn::whereIn('id', $touched)->whereNotNull('client_id')->distinct()->pluck('client_id')->all();
        $customer = (string) ((json_decode((string) ($snapshot->detail ?: $snapshot->summary), true) ?: [])['customer_id'] ?? '');
        if ($customer !== '') {
            $ids = array_merge($ids, \App\Models\Client::where('zoho_contact_id', $customer)->pluck('id')->all(),
                ZohoInvoice::withTrashed()->where('zoho_contact_id', $customer)->whereNotNull('client_id')->distinct()->pluck('client_id')->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function registerRow(string $scope, string $zohoInvoiceId): ?PatentInvoiceIn
    {
        $mirrorId = ZohoInvoice::withTrashed()->where('scope', $scope)->where('zoho_type', 'invoice')->where('zoho_id', $zohoInvoiceId)->value('id');

        return $mirrorId ? PatentInvoiceIn::where('zoho_invoice_id', $mirrorId)->first() : null;
    }

    /** @param array<int, int> $touched */
    private function afterImport(array $touched): array
    {
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
        // Waived amounts are not money received: they never become (estimated) payments.
        $paid = max(0, (float) $row->invoice_amount - (float) $row->balance_due - (float) $row->waived_amount);
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

    /** Every mirrored customer payment and credit note, then the gaps (used by finance:import-zoho). */
    public function importAll(): int
    {
        $count = 0;
        ZohoSnapshot::withTrashed()->where('resource', 'customerpayments')->orderBy('id')->chunkById(200, function ($snapshots) use (&$count) {
            foreach ($snapshots as $snapshot) {
                $this->importPayment($snapshot);
                $count++;
            }
        });
        ZohoSnapshot::withTrashed()->where('resource', 'creditnotes')->orderBy('id')->chunkById(200, function ($snapshots) use (&$count) {
            foreach ($snapshots as $snapshot) {
                $this->importCreditNote($snapshot);
                $count++;
            }
        });

        return $count;
    }
}
