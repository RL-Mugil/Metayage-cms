<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Email copy of an in-app notification, for people who chose email for that kind. */
class NotificationCopyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $heading, public string $body, public ?string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.notification-copy');
    }
}
