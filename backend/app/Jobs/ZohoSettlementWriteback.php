<?php

namespace App\Jobs;

use App\Models\PatentInvoiceIn;
use App\Models\RegisterPayment;
use App\Models\ZohoInvoice;
use App\Services\ZohoBooksService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Writes a portal settlement to Zoho Books: a customer payment for "Mark paid", a write-off
 * for an approved full waiver. One attempt; the outcome (done / failed + Zoho's reason) is
 * stored on the invoice and the Pending Payments page offers Retry.
 */
class ZohoSettlementWriteback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $invoiceId, public string $kind, public ?int $paymentId = null) {}

    public function handle(ZohoBooksService $zoho): void
    {
        $row = PatentInvoiceIn::find($this->invoiceId);
        if (! $row) {
            return;
        }
        $mirror = $row->zoho_invoice_id ? ZohoInvoice::withTrashed()->find($row->zoho_invoice_id) : null;
        if (! $mirror || ! $mirror->zoho_id || ! $zoho->isConfigured()) {
            $row->update(['zoho_writeback' => 'not_applicable', 'zoho_writeback_error' => $mirror ? 'Zoho Books is not connected.' : null]);

            return;
        }

        try {
            if ($this->kind === 'payment') {
                $payment = RegisterPayment::find($this->paymentId);
                if (! $payment || ! str_starts_with((string) $payment->zoho_payment_id, 'portal:')) {
                    $row->update(['zoho_writeback' => 'done', 'zoho_writeback_error' => null]);

                    return;
                }
                abort_unless((bool) $mirror->zoho_contact_id, 422, 'The Zoho invoice has no customer linked.');
                $zohoPaymentId = $zoho->recordCustomerPayment((string) $mirror->zoho_contact_id, (string) $mirror->zoho_id,
                    (float) $payment->amount, $payment->payment_date->toDateString(), $this->zohoMode($payment->payment_mode), $payment->reference);
                // The next import of this Zoho payment then updates this same line instead of adding one.
                $payment->update(['zoho_payment_id' => $zohoPaymentId]);
            } else {
                $zoho->writeOffInvoice((string) $mirror->zoho_id);
            }
            $row->update(['zoho_writeback' => 'done', 'zoho_writeback_error' => null]);
        } catch (\Throwable $e) {
            $message = $e instanceof \App\Exceptions\ZohoApiException
                ? 'Zoho said: HTTP '.$e->status.($e->zohoCode ? ', code '.$e->zohoCode : '').($e->zohoMessage ? ' - '.$e->zohoMessage : '')
                : mb_substr($e->getMessage(), 0, 300);
            $row->update(['zoho_writeback' => 'failed', 'zoho_writeback_error' => mb_substr($message, 0, 400)]);
            Log::warning('Zoho settlement write-back failed', ['invoice_id' => $row->id, 'kind' => $this->kind, 'error' => $message]);
        }
    }

    /** Portal payment mode → Zoho Books payment_mode value. */
    private function zohoMode(?string $mode): string
    {
        return match ($mode) {
            'UPI' => 'upi', 'Cheque' => 'check', 'Cash' => 'cash', 'Card' => 'creditcard',
            default => 'banktransfer',
        };
    }
}
