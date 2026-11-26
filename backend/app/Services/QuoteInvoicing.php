<?php

namespace App\Services;

use App\Models\AdvanceQuoteLink;
use App\Models\AuditLog;
use App\Models\PatentInvoiceIn;
use App\Models\User;
use App\Models\ZohoInvoice;
use App\Models\ZohoPaymentIndex;
use App\Models\ZohoQuoteExport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Quote-first billing, payment → invoice: when a client's payment arrives (in Zoho, as an
 * advance), earmark it for the quote, turn the quote into the tax invoice in Zoho and apply the
 * payment to that invoice. Each step checks what is already done, so a retry continues where it
 * stopped. Also the suggestions: which open quotes a payment fully pays.
 */
class QuoteInvoicing
{
    /** Remember which register invoice a quote became. */
    public static function markConverted(PatentInvoiceIn $quote, string $zohoInvoiceId): void
    {
        $mirrorId = ZohoInvoice::withTrashed()->where('zoho_type', 'invoice')->where('zoho_id', $zohoInvoiceId)->value('id');
        $invoiceId = $mirrorId ? PatentInvoiceIn::where('zoho_invoice_id', $mirrorId)->value('id') : null;
        if ($invoiceId && ! $quote->converted_invoice_id) {
            $quote->update(['converted_invoice_id' => $invoiceId]);
        }
    }

    /** The Zoho invoice a quote already became, if any. */
    public function zohoInvoiceFor(PatentInvoiceIn $quote): ?string
    {
        $export = ZohoQuoteExport::where('source_type', 'in')->where('source_id', $quote->id)->whereNotNull('invoice_id')->value('invoice_id');
        if ($export) {
            return (string) $export;
        }
        if ($quote->converted_invoice_id) {
            $mirrorId = PatentInvoiceIn::whereKey($quote->converted_invoice_id)->value('zoho_invoice_id');

            return $mirrorId ? (string) ZohoInvoice::withTrashed()->whereKey($mirrorId)->value('zoho_id') : null;
        }

        return null;
    }

    /** Turn the quote into an invoice in Zoho: a quote sent from the portal, or one created in Zoho. Returns the Zoho invoice id. */
    public function convertInZoho(PatentInvoiceIn $quote): string
    {
        if ($existing = $this->zohoInvoiceFor($quote)) {
            return $existing;
        }
        if (ZohoQuoteExport::where('source_type', 'in')->where('source_id', $quote->id)->where('state', 'confirmed')->exists()) {
            $result = app(ZohoQuoteWriter::class)->convertToInvoice('in', $quote->id);
            abort_unless(! empty($result['invoice_id']), 409, $result['message'] ?? 'Zoho did not return the invoice.');

            return (string) $result['invoice_id'];
        }
        $estimate = $quote->zoho_invoice_id ? ZohoInvoice::withTrashed()->where('zoho_type', 'quote')->whereKey($quote->zoho_invoice_id)->value('zoho_id') : null;
        abort_unless($estimate, 422, 'This quote is not in Zoho yet: send it to Zoho first.');
        $invoice = app(ZohoBooksService::class)->createInvoiceFromEstimate((string) $estimate);
        $id = (string) ($invoice['invoice_id'] ?? '');
        abort_if($id === '', 409, 'Zoho did not return the invoice.');
        Cache::put('quote_first_expected:'.$id, true, now()->addHour());   // not an "invoice before payment"
        AuditLog::create(['user_id' => auth()->id(), 'action' => 'zoho_invoice_from_estimate', 'subject_type' => 'PatentInvoiceIn', 'subject_id' => $quote->id,
            'metadata' => ['estimate_id' => $estimate, 'invoice_id' => $id], 'ip_address' => request()?->ip(), 'user_agent' => request()?->userAgent()]);

        return $id;
    }

    /**
     * Earmark the payment, create the invoice in Zoho, apply the payment. Returns what was done.
     *
     * @return array{earmarked: float, invoice_id: string, applied: bool, message: string}
     */
    public function fromPayment(PatentInvoiceIn $quote, string $paymentId, ?string $overrideReason, User $user): array
    {
        abort_unless($quote->type === 'quote', 422, 'Only a quote can be turned into an invoice.');
        $payment = ZohoPaymentIndex::where('remote_id', $paymentId)->first();
        abort_unless($payment, 404, 'That payment is not in the portal yet.');
        $advance = app(PaymentsReceived::class)->advance($paymentId);
        abort_unless($advance && (int) $advance['client_id'] === (int) $quote->client_id, 422, 'That payment is from another client.');

        // 1. Earmark (once).
        $link = AdvanceQuoteLink::where('zoho_payment_id', $paymentId)->where('patent_invoice_in_id', $quote->id)->first();
        if (! $link) {
            $free = (float) $advance['unused'] - (float) AdvanceQuoteLink::where('zoho_payment_id', $paymentId)->whereNull('applied_at')->sum('amount');
            $amount = round(min($free, QuoteFirst::paidState($quote)['due']), 2);
            abort_if($amount <= 0, 422, 'This payment has no unused amount left.');
            $link = AdvanceQuoteLink::create(['zoho_payment_id' => $paymentId, 'payment_number' => $advance['number'], 'client_id' => $quote->client_id,
                'patent_invoice_in_id' => $quote->id, 'amount' => $amount, 'created_by_id' => $user->id, 'note' => 'Payment for this quote']);
        }

        // 2. The rule: paid (or a reason).
        QuoteFirst::check($quote->client_id, $quote->fresh(), $overrideReason, $user, 'invoice from payment');

        // 3. The tax invoice in Zoho.
        $invoiceId = $this->convertInZoho($quote);
        $sync = app(ZohoMirrorSync::class);
        rescue(fn () => $sync->syncRemote('invoices', $invoiceId), null, true);
        self::markConverted($quote->fresh(), $invoiceId);

        // 4. Apply the payment to it.
        $applied = (bool) $link->applied_at;
        $message = 'Invoice created in Zoho and the payment applied.';
        if (! $applied) {
            try {
                app(ZohoBooksService::class)->applyPaymentToInvoice($invoiceId, $paymentId, round((float) $link->amount / ((float) $payment->exchange_rate ?: 1.0), 2));
                $link->update(['applied_zoho_invoice_id' => $invoiceId, 'applied_at' => now(), 'applied_by_id' => $user->id]);
                $applied = true;
                rescue(fn () => $sync->syncRemote('customerpayments', $paymentId), null, true);
                rescue(fn () => $sync->syncRemote('invoices', $invoiceId), null, true);
            } catch (\Throwable $e) {
                report($e);
                $message = 'Invoice created in Zoho; applying the payment failed ('.mb_substr($e->getMessage(), 0, 160).'). Try again: the invoice will not be created twice.';
            }
        }
        AuditLog::create(['user_id' => $user->id, 'action' => 'invoice_from_payment', 'subject_type' => 'PatentInvoiceIn', 'subject_id' => $quote->id,
            'metadata' => ['payment' => $advance['number'], 'zoho_invoice_id' => $invoiceId, 'amount' => (float) $link->amount, 'applied' => $applied],
            'ip_address' => request()?->ip(), 'user_agent' => request()?->userAgent()]);

        return ['earmarked' => (float) $link->amount, 'invoice_id' => $invoiceId, 'applied' => $applied, 'message' => $message];
    }

    /**
     * Open quotes of the client that this unused amount pays in full (TDS shortfall allowed).
     *
     * @return array<int, array{id: int, number: string, due: float}>
     */
    public function suggestions(?int $clientId, float $unused): array
    {
        if (! $clientId || $unused <= 0 || ! QuoteFirst::enabled()) {
            return [];
        }

        return PatentInvoiceIn::openQuotes()->where('client_id', $clientId)->get()->map(function (PatentInvoiceIn $q) use ($unused) {
            $s = QuoteFirst::paidState($q);

            return $s['due'] > 0 && $unused + $s['allowance'] + 0.5 >= $s['due'] ? ['id' => $q->id, 'number' => $q->invoice_uin ?: $q->zoho_number ?: "Q-{$q->id}", 'due' => $s['due']] : null;
        })->filter()->values()->all();
    }
}
