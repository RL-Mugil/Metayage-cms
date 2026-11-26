<?php

namespace App\Mail;

use App\Models\CollectionReminder;
use App\Models\PatentInvoiceIn;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/** A payment reminder to a client (approved by its Client Manager or Accounts), with the statement attached. */
class ClientPaymentReminderMail extends Mailable
{
    use Queueable;

    /** @param array{bytes: string, name: string, mime: string} $statement */
    public function __construct(
        public CollectionReminder $reminder,
        public Collection $invoices,
        public array $statement,
        public array $letterhead,
        public ?string $replyToAddress = null,
        public string $closing = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->reminder->subject,
            replyTo: $this->replyToAddress ? [new Address($this->replyToAddress)] : []);
    }

    public function content(): Content
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rows = $this->invoices->map(function (PatentInvoiceIn $i) use ($e) {
            if ($i->type === 'quote') {
                // A proforma: payable now; the tax invoice follows the payment.
                $late = 'Proforma · payable now';
                $amount = 'INR '.number_format(\App\Services\QuoteFirst::paidState($i)['due'], 2);
            } else {
                $late = $i->due_date && $i->due_date->isPast() ? (int) $i->due_date->diffInDays(now()).' days overdue' : 'due '.$i->due_date?->format('d M Y');
                $amount = ($i->currency ?: 'INR').' '.number_format((float) $i->balance_due, 2);
            }

            return '<tr><td style="padding:6px 8px;border-bottom:1px solid #eef2f7;font-family:monospace">'.$e($i->invoice_uin ?: $i->zoho_number).'</td>'
                .'<td style="padding:6px 8px;border-bottom:1px solid #eef2f7">'.$e($i->invoice_date?->format('d M Y')).'</td>'
                .'<td style="padding:6px 8px;border-bottom:1px solid #eef2f7">'.$e($late).'</td>'
                .'<td style="padding:6px 8px;border-bottom:1px solid #eef2f7;text-align:right">'.$e($amount).'</td></tr>';
        })->implode('');
        $body = nl2br($e($this->reminder->body));
        $head = $this->letterhead;
        $html = '<!doctype html><html><body style="margin:0;background:#f4f6f8;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1f2937">'
            .'<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">'
            .'<table width="620" cellpadding="0" cellspacing="0" style="max-width:620px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden">'
            .'<tr><td style="padding:18px 24px;border-bottom:3px solid #15803d"><strong style="font-size:16px;color:#15803d">'.$e($head['company_name']).'</strong></td></tr>'
            .'<tr><td style="padding:22px 24px;font-size:14px;line-height:1.55">'
            .'<p style="margin:0 0 12px">Dear '.$e(collect($this->reminder->recipients)->pluck('name')->filter()->first() ?: 'Sir / Madam').',</p>'
            .'<p style="margin:0 0 16px">'.$body.'</p>'
            .'<table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;border-collapse:collapse">'
            .'<tr style="background:#1e3a5f;color:#fff"><th style="padding:6px 8px;text-align:left">Invoice</th><th style="padding:6px 8px;text-align:left">Date</th><th style="padding:6px 8px;text-align:left">Status</th><th style="padding:6px 8px;text-align:right">Balance</th></tr>'
            .$rows.'</table>'
            .'<p style="margin:16px 0 0">'.$e($this->closing).'</p>'
            .'</td></tr>'
            .'<tr><td style="padding:14px 24px;background:#f8fafc;font-size:11px;color:#6b7280">'.$e($head['company_name']).' · '.$e($head['address']).' · '.$e($head['phone']).'</td></tr>'
            .'</table></td></tr></table></body></html>';

        return new Content(htmlString: $html);
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->statement['bytes'], $this->statement['name'])->withMime($this->statement['mime'])];
    }
}
