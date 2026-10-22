<?php

namespace App\Mail;

use App\Models\CpiBooking;
use App\Support\BrandedEmail as B;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Every consultation email (visitor, team and invited staff) as branded HTML with a plain-text alternative and, where it
 * helps, a calendar file. Use the static builders; $c is the context from CpiBookingService::mailContext():
 *   tzCompany, tzVisitor, manageUrl (?string), joinUrl (?string), staff (list of names), bookUrl
 */
class CpiBookingMail extends Mailable
{
    public function __construct(
        public string $kind,
        public string $subjectLine,
        public string $htmlBody,
        public string $plainBody,
        public ?string $icsData = null,
        public ?string $replyToEmail = null,
        public ?string $replyToName = null,
        public string $icsMethod = 'REQUEST',
    ) {
        $this->withSymfonyMessage(fn ($message) => $message->text($plainBody));
        if ($icsData !== null) {
            $this->attachData($icsData, 'consultation.ics', ['mime' => 'text/calendar; charset=UTF-8; method='.$icsMethod]);
        }
        if ($replyToEmail) {
            $this->replyTo($replyToEmail, $replyToName);
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->htmlBody);
    }

    // ---- shared pieces -------------------------------------------------------------------------------------------------

    private static function first(string $name): string
    {
        return trim(explode(' ', trim($name))[0] ?? '') ?: $name;
    }

    private static function stamp(CpiBooking $b, string $tz): string
    {
        return $b->start_utc->copy()->setTimezone($tz)->format('D j M Y, g:i A T');
    }

    private static function joinButton(array $c): string
    {
        return ! empty($c['joinUrl']) ? B::button('Join the call', $c['joinUrl']) : '';
    }

    private static function manageButtons(array $c): string
    {
        return ! empty($c['manageUrl']) ? B::button('Reschedule', $c['manageUrl'], false).B::button('Cancel', $c['cancelUrl'] ?? $c['manageUrl'], false) : '';
    }

    private static function label(string $text): string
    {
        return '<p style="margin:22px 0 8px;font-size:13px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:'.B::MUTED.'">'.B::e($text).'</p>';
    }

    private static function visitorRows(CpiBooking $b, array $c, bool $withStaff = true): string
    {
        $rows = [
            'Name' => '<strong>'.B::e($b->name).'</strong>',
            'Email' => '<a href="mailto:'.B::e($b->email).'" style="color:'.B::DEEP.';text-decoration:none">'.B::e($b->email).'</a>',
            'Company' => B::e($b->organization ?: 'Not given'),
            'Their time' => B::e(self::stamp($b, $c['tzVisitor'])).' <span style="color:'.B::MUTED.'">('.B::e($c['tzVisitor']).')</span>',
        ];
        if ($withStaff) {
            $rows['Invited staff'] = ! empty($c['staff']) ? B::e(implode(', ', $c['staff'])) : '<span style="color:'.B::MUTED.'">Nobody assigned yet</span>';
        }

        return B::rows($rows);
    }

    private static function topicBlock(CpiBooking $b): string
    {
        $topic = trim((string) $b->topic);

        return self::label('What they would like to talk about')
            .'<div style="background:#f6f8fa;border-left:4px solid '.B::BLUE.';border-radius:6px;padding:14px 16px;font-size:15px;line-height:1.65;color:'.B::INK.'">'
            .($topic !== '' ? nl2br(B::e($topic)) : '<span style="color:'.B::MUTED.'">No topic given.</span>').'</div>';
    }

    // ---- visitor -------------------------------------------------------------------------------------------------------

    public static function confirmation(CpiBooking $b, array $c, string $ics): self
    {
        $first = self::first($b->name);
        $when = self::stamp($b, $c['tzVisitor']);
        $content = '<p style="margin:0 0 16px;font-size:16px;line-height:1.6">Hello '.B::e($first).', you are booked. Here are the details.</p>'
            .B::dateTile($b->start_utc, $b->end_utc, $c['tzVisitor'])
            .(! empty($c['joinUrl']) ? '<div style="margin-top:18px">'.self::joinButton($c).'</div>' : '')
            .self::label('What to expect')
            .B::steps(['We start with your technology, not a pitch.', 'No preparation is needed. A rough idea of what you are building is plenty.', 'We will suggest a sensible next step before we finish.'])
            .'<p style="margin:22px 0 0;font-size:14px;line-height:1.6;color:'.B::MUTED.'">The calendar invitation is attached to this email (consultation.ics). Open it to add the call to your calendar.</p>'
            .(! empty($c['manageUrl']) ? '<p style="margin:18px 0 8px;font-size:14px;color:'.B::MUTED.'">Plans changed? You can move the call or cancel it yourself:</p><div>'.self::manageButtons($c).'</div>' : '')
            .'<p style="margin:14px 0 0;font-size:12px;line-height:1.6;color:#7b848c">This call does not create an attorney&ndash;client relationship, and nothing discussed before an engagement agreement is legal advice.</p>';

        $plain = "Hello {$first},\n\nYour CanPatentIt consultation is confirmed for {$when} ({$c['tzVisitor']}).\n".(! empty($c['joinUrl']) ? "Join the call: {$c['joinUrl']}\n" : '')
            ."\nWhat to expect: we start with your technology, not a pitch. No preparation is needed.\nThe calendar invitation is attached (consultation.ics).\n".(! empty($c['manageUrl']) ? "\nReschedule or cancel: {$c['manageUrl']}\n" : '')
            ."\nThis call does not create an attorney-client relationship, and nothing discussed before an engagement agreement is legal advice.\n\nCanPatentIt";

        return new self('confirmation', 'Your CanPatentIt consultation is confirmed',
            B::frame("You are booked for {$when}", 'Consultation confirmed', 'Your consultation is confirmed', 'We look forward to talking with you.', $content), $plain, $ics);
    }

    /** Rescheduled: $old is the previous start (UTC). $audience: visitor | team | staff. */
    public static function rescheduled(CpiBooking $b, array $c, string $audience, \Carbon\CarbonInterface $old, string $ics, string $by): self
    {
        $tz = $audience === 'visitor' ? $c['tzVisitor'] : $c['tzCompany'];
        $oldStamp = $old->copy()->setTimezone($tz)->format('D j M Y, g:i A T');
        $new = self::stamp($b, $tz);
        $who = $by === 'visitor' ? $b->name.' moved' : 'The call was moved';
        $intro = $audience === 'visitor'
            ? 'Hello '.B::e(self::first($b->name)).', your consultation has been moved to a new time. The updated calendar invitation is attached and replaces the old one.'
            : B::e($by === 'visitor' ? "{$b->name} moved their consultation to a new time." : 'This consultation was moved to a new time.').' The updated calendar invitation is attached.';
        $content = '<p style="margin:0 0 14px;font-size:16px;line-height:1.6">'.$intro.'</p>'
            .'<p style="margin:0 0 10px;font-size:14px;color:'.B::MUTED.'">Previously: <span style="text-decoration:line-through">'.B::e($oldStamp).'</span></p>'
            .B::dateTile($b->start_utc, $b->end_utc, $tz)
            .(! empty($c['joinUrl']) ? '<div style="margin-top:18px">'.self::joinButton($c).'</div>' : '')
            .($audience === 'visitor' ? (! empty($c['manageUrl']) ? '<p style="margin:18px 0 8px;font-size:14px;color:'.B::MUTED.'">Need another change?</p><div>'.self::manageButtons($c).'</div>' : '') : self::visitorRows($b, $c));

        $plain = ($audience === 'visitor' ? 'Hello '.self::first($b->name).",\n\nYour consultation has moved." : "Consultation moved ({$who}).")."\nNew time: {$new}".($audience === 'visitor' ? " ({$c['tzVisitor']})" : " ({$c['tzCompany']})")."\nPreviously: {$oldStamp}\n"
            .(! empty($c['joinUrl']) ? "Join the call: {$c['joinUrl']}\n" : '').($audience === 'visitor' && ! empty($c['manageUrl']) ? "Reschedule or cancel: {$c['manageUrl']}\n" : '')."\nThe updated calendar invitation is attached.\n";

        return new self('rescheduled-'.$audience, $audience === 'visitor' ? 'Your CanPatentIt consultation has been rescheduled' : "CanPatentIt booking moved: {$b->name}, {$new}",
            B::frame("Moved to {$new}", 'Consultation rescheduled', $audience === 'visitor' ? 'Your consultation has a new time' : 'Consultation rescheduled', $new, $content), $plain, $ics, $audience === 'visitor' ? null : $b->email, $audience === 'visitor' ? null : $b->name);
    }

    /** $audience: visitor | team | staff. */
    public static function cancelled(CpiBooking $b, array $c, string $audience, ?string $ics = null): self
    {
        $tz = $audience === 'visitor' ? $c['tzVisitor'] : $c['tzCompany'];
        $when = self::stamp($b, $tz);
        $intro = $audience === 'visitor'
            ? 'Hello '.B::e(self::first($b->name)).', your consultation has been cancelled. You can book another time whenever it suits you.'
            : 'This booking has been cancelled. The time slot is free again.';
        $content = '<p style="margin:0 0 14px;font-size:16px;line-height:1.6">'.$intro.'</p>'
            .B::dateTile($b->start_utc, $b->end_utc, $tz)
            .($audience === 'visitor' ? '<div style="margin-top:20px">'.B::button('Book a new time', $c['bookUrl']).'</div>' : B::rows([
                'Name' => '<strong>'.B::e($b->name).'</strong>',
                'Email' => '<a href="mailto:'.B::e($b->email).'" style="color:'.B::DEEP.';text-decoration:none">'.B::e($b->email).'</a>',
            ]));

        $plain = ($audience === 'visitor' ? 'Hello '.self::first($b->name).",\n\nYour consultation on {$when} has been cancelled. Book a new time: {$c['bookUrl']}\n" : "Consultation cancelled: {$b->name} <{$b->email}>, {$when}.\n");

        return new self('cancelled-'.$audience, $audience === 'visitor' ? 'Your CanPatentIt consultation has been cancelled' : 'CanPatentIt consultation cancelled',
            B::frame("{$b->name} cancelled {$when}", 'Booking cancelled', $audience === 'visitor' ? 'Your consultation is cancelled' : 'Consultation cancelled', $when, $content), $plain, $ics, null, null, 'CANCEL');
    }

    // ---- team and staff ------------------------------------------------------------------------------------------------

    public static function notification(CpiBooking $b, array $c, string $ics): self
    {
        $when = self::stamp($b, $c['tzCompany']);
        $content = '<p style="margin:0 0 14px;font-size:16px;line-height:1.6">A visitor has booked a call. The calendar invitation is attached.</p>'
            .B::dateTile($b->start_utc, $b->end_utc, $c['tzCompany']).self::visitorRows($b, $c).self::topicBlock($b)
            .'<div style="margin-top:22px">'.self::joinButton($c).B::button('Reply to '.self::first($b->name), 'mailto:'.$b->email.'?subject='.rawurlencode('Your CanPatentIt consultation'), empty($c['joinUrl'])).'</div>';
        $plain = "New consultation booking\n\nWhen: {$when} ({$c['tzCompany']})\nTheir time: ".self::stamp($b, $c['tzVisitor'])." ({$c['tzVisitor']})\nName: {$b->name}\nEmail: {$b->email}\nCompany: ".($b->organization ?: '-')."\n\n".(trim((string) $b->topic) ?: '(no topic given)')."\n";

        return new self('notification', "CanPatentIt booking: {$b->name}, {$when}",
            B::frame("{$b->name} booked {$when}", 'New booking', 'New consultation booking', $when, $content, '<p style="margin:0">You receive this because you are the CanPatentIt contact recipient.</p>'),
            $plain, $ics, $b->email, $b->name);
    }

    /** Sent to an IPFlow staff member when they are added to a call. */
    public static function staffInvite(CpiBooking $b, array $c, string $ics): self
    {
        $when = self::stamp($b, $c['tzCompany']);
        $content = '<p style="margin:0 0 14px;font-size:16px;line-height:1.6">You have been added to a CanPatentIt consultation. The calendar invitation is attached, so the call will appear in your calendar.</p>'
            .B::dateTile($b->start_utc, $b->end_utc, $c['tzCompany']).self::visitorRows($b, $c).self::topicBlock($b)
            .'<div style="margin-top:22px">'.self::joinButton($c).'</div>';
        $plain = "You have been added to a CanPatentIt consultation.\n\nWhen: {$when} ({$c['tzCompany']})\nWith: {$b->name} <{$b->email}>".($b->organization ? ", {$b->organization}" : '')."\n".(! empty($c['joinUrl']) ? "Join the call: {$c['joinUrl']}\n" : '')."\n".(trim((string) $b->topic) ?: '(no topic given)')."\n";

        return new self('staff-invite', "You are invited: CanPatentIt consultation with {$b->name}, {$when}",
            B::frame("Consultation with {$b->name}, {$when}", 'Invitation', 'You are invited to a consultation', $when, $content, '<p style="margin:0">You receive this because an administrator assigned you to the call.</p>'), $plain, $ics);
    }
}
