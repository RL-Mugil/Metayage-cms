<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DocketDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $profileName, public readonly array $items, public readonly array $counts) {}

    public function envelope(): Envelope { return new Envelope(subject: "MyIPStrategy Docket Reminder — {$this->counts['actionable']} actionable"); }
    public function content(): Content { return new Content(view: 'emails.docket-digest'); }
}
