<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Monday digest for partners and managers: deadlines, overdue payments, renewals, collections. */
class WeeklyDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<string, mixed> $digest see App\Console\Commands\SendWeeklyDigest::build() */
    public function __construct(public string $name, public array $digest) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Weekly digest — '.$this->digest['period']);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.weekly-digest');
    }
}
