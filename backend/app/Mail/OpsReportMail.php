<?php

namespace App\Mail;

use App\Services\OpsDailyReport;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/** HTML email with a plain-text alternative, used for the morning report and the "recovered" notice. */
class OpsReportMail extends Mailable
{
    public function __construct(public string $subjectLine, public string $htmlBody, public string $plainBody, public string $kind = 'daily')
    {
        $this->withSymfonyMessage(fn ($message) => $message->text($plainBody));
    }

    public static function daily(array $report): self
    {
        return new self($report['subject'], $report['html'], $report['text'], 'daily');
    }

    public static function recovery(Collection $incidents): self
    {
        $r = app(OpsDailyReport::class)->recoveryMail($incidents);

        return new self($r['subject'], $r['html'], $r['text'], 'recovery');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->htmlBody);
    }
}
