<?php

namespace App\Mail;

use App\Models\PatentInvoiceIn;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent when a client approves a renewal and the invoice is raised (RenewalActionController::approve()). */
class RenewalInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PatentInvoiceIn $invoice,
        public string $clientName,
        public string $portalUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Renewal Invoice {$this->invoice->invoice_uin} — {$this->invoice->docket_number}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.renewal-invoice');
    }
}
