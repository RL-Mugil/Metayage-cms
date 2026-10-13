<?php

namespace App\Services;

use App\Models\CpiAvailabilityRule;
use App\Models\CpiBlackout;
use App\Models\CpiBooking;
use App\Models\SiteEnquiry;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/** Slot generation and booking for consultation calls on canpatentit.com. */
class CpiBookingService
{
    private const KEY = 'cpi_booking';

    public static function settings(): array
    {
        $saved = json_decode((string) SiteSettings::get(self::KEY, ''), true) ?: [];

        return array_merge([
            'slot_minutes' => 30,
            'buffer_minutes' => 0,
            'min_notice_hours' => 12,
            'horizon_days' => 21,
            'timezone' => 'Asia/Kolkata',
        ], $saved);
    }

    public static function saveSettings(array $s): array
    {
        $clean = [
            'slot_minutes' => max(15, min(120, (int) $s['slot_minutes'])),
            'buffer_minutes' => max(0, min(60, (int) $s['buffer_minutes'])),
            'min_notice_hours' => max(0, min(168, (int) $s['min_notice_hours'])),
            'horizon_days' => max(1, min(90, (int) $s['horizon_days'])),
            'timezone' => in_array($s['timezone'], \DateTimeZone::listIdentifiers(), true) ? $s['timezone'] : 'Asia/Kolkata',
        ];
        SiteSettings::put(self::KEY, json_encode($clean));

        return $clean;
    }

    /** Open slot start times (UTC ISO strings) for a calendar day in the booking timezone. */
    public function slotsForDate(string $date): array
    {
        $cfg = self::settings();
        $tz = $cfg['timezone'];
        try {
            $day = CarbonImmutable::createFromFormat('Y-m-d', $date, $tz)->startOfDay();
        } catch (\Throwable) {
            return [];
        }
        if ($day->format('Y-m-d') !== $date) {
            return [];
        }
        if (CpiBlackout::whereDate('day', $date)->exists()) {
            return [];
        }

        $earliest = CarbonImmutable::now('UTC')->addHours($cfg['min_notice_hours']);
        $latest = CarbonImmutable::now('UTC')->addDays($cfg['horizon_days'])->endOfDay();
        $length = $cfg['slot_minutes'];
        $step = $length + $cfg['buffer_minutes'];

        $slots = [];
        foreach (CpiAvailabilityRule::where('weekday', $day->dayOfWeek)->orderBy('start_time')->get() as $rule) {
            [$sh, $sm] = array_map('intval', explode(':', $rule->start_time));
            [$eh, $em] = array_map('intval', explode(':', $rule->end_time));
            $cursor = $day->setTime($sh, $sm);
            $end = $day->setTime($eh, $em);
            while ($cursor->addMinutes($length)->lte($end)) {
                $utc = $cursor->utc();
                if ($utc->gte($earliest) && $utc->lte($latest)) {
                    $slots[$utc->format('Y-m-d\TH:i:s\Z')] = $utc;
                }
                $cursor = $cursor->addMinutes($step);
            }
        }
        if (! $slots) {
            return [];
        }

        $taken = CpiBooking::where('status', 'confirmed')
            ->whereBetween('start_utc', [$day->utc()->subDay(), $day->utc()->addDays(2)])
            ->get()->map(fn ($b) => $b->start_utc->utc()->format('Y-m-d\TH:i:s\Z'))->all();

        return array_values(array_diff(array_keys($slots), $taken));
    }

    /** Dates (booking timezone) in the booking window that still have at least one open slot. */
    public function openDays(): array
    {
        $cfg = self::settings();
        $today = CarbonImmutable::now($cfg['timezone'])->startOfDay();
        $days = [];
        for ($i = 0; $i <= $cfg['horizon_days']; $i++) {
            $d = $today->addDays($i)->format('Y-m-d');
            if ($this->slotsForDate($d)) {
                $days[] = $d;
            }
        }

        return $days;
    }

    /**
     * @return array{booking: CpiBooking, token: string}|null null when the slot is not available
     */
    public function book(array $data, ?string $ip, ?string $userAgent): ?array
    {
        $start = CarbonImmutable::parse($data['start'])->utc();
        $cfg = self::settings();
        $date = $start->setTimezone($cfg['timezone'])->format('Y-m-d');
        if (! in_array($start->format('Y-m-d\TH:i:s\Z'), $this->slotsForDate($date), true)) {
            return null;
        }

        $token = Str::random(40);
        try {
            $booking = DB::transaction(function () use ($data, $start, $cfg, $token, $ip, $userAgent) {
                // lockForUpdate keeps two simultaneous requests for one slot from both passing the check;
                // the partial unique index is the final guard.
                $clash = CpiBooking::where('status', 'confirmed')->where('start_utc', $start)->lockForUpdate()->exists();
                if ($clash) {
                    return null;
                }
                $booking = CpiBooking::create([
                    'start_utc' => $start,
                    'end_utc' => $start->addMinutes($cfg['slot_minutes']),
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'organization' => $data['organization'] ?? null,
                    'topic' => $data['topic'] ?? null,
                    'visitor_timezone' => $data['timezone'] ?? null,
                    'status' => 'confirmed',
                    'manage_token_hash' => hash('sha256', $token),
                ]);
                SiteEnquiry::create([
                    'source' => 'canpatentit', 'type' => 'booking', 'name' => $data['name'], 'email' => $data['email'],
                    'organization' => $data['organization'] ?? null,
                    'message' => 'Consultation booked for '.$start->setTimezone($cfg['timezone'])->format('D j M Y, g:i A T').".\n".($data['topic'] ?? ''),
                    'payload' => ['booking_id' => (string) $booking->id, 'start_utc' => $start->toIso8601String()],
                    'ip_address' => $ip, 'user_agent' => mb_substr((string) $userAgent, 0, 255), 'mail_status' => 'pending',
                ]);

                return $booking;
            });
        } catch (QueryException) {
            return null; // unique index tripped: someone else got there first
        }
        if (! $booking) {
            return null;
        }

        $this->sendEmails($booking, $token);

        return ['booking' => $booking, 'token' => $token];
    }

    public function cancel(CpiBooking $booking): void
    {
        $booking->update(['status' => 'cancelled']);
        $recipient = config('services.contact.recipient');
        try {
            if ($recipient) {
                Mail::raw("Consultation cancelled: {$booking->name} <{$booking->email}>, ".$booking->start_utc->utc()->format('D j M Y H:i').' UTC.', fn ($m) => $m->to($recipient)->subject('CanPatentIt consultation cancelled'));
            }
        } catch (\Throwable $e) {
            Log::error('Booking cancel notice failed', ['error' => $e->getMessage()]);
        }
    }

    private function sendEmails(CpiBooking $b, string $token): void
    {
        $cfg = self::settings();
        $when = $b->start_utc->copy()->setTimezone($cfg['timezone'])->format('D j M Y, g:i A T');
        $cancel = rtrim((string) config('app.url'), '/')."/api/public/cpi/cancel/{$b->id}/{$token}";
        $ics = $this->ics($b);
        $recipient = config('services.contact.recipient');
        $mailOk = true;

        try {
            Mail::raw(
                "Hello {$b->name},\n\nYour CanPatentIt consultation is confirmed for {$when} ({$cfg['timezone']}).\n\n"
                ."We will start with your technology, not a pitch. No preparation is needed.\n\n"
                ."Need to cancel? Use this link: {$cancel}\n\n"
                ."This call does not create an attorney-client relationship, and nothing discussed before an engagement agreement is legal advice.\n\nCanPatentIt",
                function ($m) use ($b, $ics) {
                    $m->to($b->email, $b->name)->subject('Your CanPatentIt consultation is confirmed')
                        ->attachData($ics, 'consultation.ics', ['mime' => 'text/calendar']);
                }
            );
        } catch (\Throwable $e) {
            $mailOk = false;
            Log::error('Booking confirmation email failed', ['error' => $e->getMessage()]);
        }

        try {
            if ($recipient) {
                Mail::raw("New consultation booking\n\nWhen: {$when} ({$cfg['timezone']})\nName: {$b->name}\nEmail: {$b->email}\nCompany: ".($b->organization ?: '-')."\n\n".($b->topic ?: '(no topic given)'),
                    fn ($m) => $m->to($recipient)->replyTo($b->email, $b->name)->subject("CanPatentIt booking: {$b->name}, {$when}")->attachData($ics, 'consultation.ics', ['mime' => 'text/calendar']));
            } else {
                $mailOk = false;
            }
        } catch (\Throwable $e) {
            $mailOk = false;
            Log::error('Booking notification email failed', ['error' => $e->getMessage()]);
        }

        SiteEnquiry::where('type', 'booking')->where('payload->booking_id', (string) $b->id)->update(['mail_status' => $mailOk ? 'sent' : 'failed']);
    }

    private function ics(CpiBooking $b): string
    {
        $fmt = fn (Carbon $c) => $c->copy()->utc()->format('Ymd\THis\Z');

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CanPatentIt//Booking//EN\r\nMETHOD:PUBLISH\r\nBEGIN:VEVENT\r\n"
            ."UID:booking-{$b->id}@canpatentit.com\r\nDTSTAMP:".$fmt(now())."\r\nDTSTART:".$fmt($b->start_utc)."\r\nDTEND:".$fmt($b->end_utc)."\r\n"
            ."SUMMARY:CanPatentIt consultation\r\nDESCRIPTION:Consultation with CanPatentIt\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }
}
