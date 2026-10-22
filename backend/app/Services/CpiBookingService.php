<?php

namespace App\Services;

use App\Models\CpiAvailabilityRule;
use App\Models\CpiBlackout;
use App\Mail\CpiBookingMail;
use App\Models\CpiBooking;
use App\Models\SiteEnquiry;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/** Slot generation and booking for consultation calls on canpatentit.com. */
class CpiBookingService
{
    private const KEY = 'cpi_booking';
    private const SITE = 'https://canpatentit.com';

    public static function settings(): array
    {
        $saved = json_decode((string) SiteSettings::get(self::KEY, ''), true) ?: [];

        return array_merge([
            'slot_minutes' => 30,
            'buffer_minutes' => 0,
            'min_notice_hours' => 12,
            'horizon_days' => 21,
            'timezone' => 'Asia/Kolkata',
            'meeting_url' => '',
            'visitor_reschedule_hours' => 12,
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
            'meeting_url' => self::cleanUrl($s['meeting_url'] ?? ''),
            'visitor_reschedule_hours' => max(0, min(168, (int) ($s['visitor_reschedule_hours'] ?? 12))),
        ];
        SiteSettings::put(self::KEY, json_encode($clean));

        return $clean;
    }

    /** Only an https address is accepted as the join link; anything else is dropped. */
    private static function cleanUrl(?string $u): string
    {
        $u = trim((string) $u);

        return $u !== '' && str_starts_with($u, 'https://') && filter_var($u, FILTER_VALIDATE_URL) && strlen($u) <= 300 ? $u : '';
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
                    'manage_token_enc' => Crypt::encryptString($token),
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

    /** Every open slot (UTC) in the booking window, for the visitor-side time zone picker and the admin reschedule picker. */
    public function slotsInRange(bool $fresh = false): array
    {
        $build = function (): array {
            $cfg = self::settings();
            $today = CarbonImmutable::now($cfg['timezone'])->startOfDay();
            $all = [];
            for ($i = 0; $i <= $cfg['horizon_days']; $i++) {
                $all = array_merge($all, $this->slotsForDate($today->addDays($i)->format('Y-m-d')));
            }
            sort($all);

            return array_values(array_unique($all));
        };

        return $fresh ? $build() : Cache::remember('cpi_slots_range', 45, $build);
    }

    /** Context every booking email needs: both time zones, the visitor's manage links, the join link and the invited staff. */
    public function mailContext(CpiBooking $b, ?string $token = null): array
    {
        $cfg = self::settings();
        $token ??= $b->manageToken();
        $base = self::SITE;

        return [
            'tzCompany' => $cfg['timezone'],
            'tzVisitor' => $b->visitorZone($cfg['timezone']),
            'manageUrl' => $token ? "{$base}/booking/manage#{$b->id}.{$token}" : null,
            'cancelUrl' => $token ? "{$base}/booking/manage?action=cancel#{$b->id}.{$token}" : null,
            'joinUrl' => ($cfg['meeting_url'] ?? '') !== '' ? $cfg['meeting_url'] : null,
            'staff' => $b->staff()->pluck('name')->all(),
            'bookUrl' => $base.'/book',
        ];
    }

    /**
     * Move a confirmed booking to a new time. $by is "visitor" (must use an open slot and respect the cut-off) or
     * "admin" (any time that does not clash). Returns ok, or why not: cancelled | too_late | unavailable | taken.
     *
     * @return array{ok: bool, error?: string, booking?: CpiBooking}
     */
    public function reschedule(CpiBooking $b, string $newStart, string $by, ?string $rawToken = null, bool $anyTime = false): array
    {
        $cfg = self::settings();
        $new = CarbonImmutable::parse($newStart)->utc();

        if ($b->status !== 'confirmed') {
            return ['ok' => false, 'error' => 'cancelled'];
        }
        if ($by === 'visitor' && $b->start_utc->copy()->subHours((int) $cfg['visitor_reschedule_hours'])->isPast()) {
            return ['ok' => false, 'error' => 'too_late'];
        }
        $isOpenSlot = in_array($new->format('Y-m-d\TH:i:s\Z'), $this->slotsForDate($new->setTimezone($cfg['timezone'])->format('Y-m-d')), true);
        if ($new->isPast() || (! ($by === 'admin' && $anyTime) && ! $isOpenSlot)) {
            return ['ok' => false, 'error' => 'unavailable'];
        }

        $old = $b->start_utc->copy();
        try {
            $moved = DB::transaction(function () use ($b, $new, $cfg, $by, $rawToken, $old) {
                if (CpiBooking::where('status', 'confirmed')->where('start_utc', $new)->where('id', '!=', $b->id)->lockForUpdate()->exists()) {
                    return false;
                }
                $b->update([
                    'start_utc' => $new, 'end_utc' => $new->addMinutes($cfg['slot_minutes']), 'rescheduled_from_utc' => $old,
                    'sequence' => $b->sequence + 1, 'reschedule_count' => $b->reschedule_count + 1, 'last_rescheduled_by' => $by,
                ]);
                // Later emails must carry the same manage link. Older bookings stored only a hash: keep the visitor's own
                // token if they used it, otherwise issue a fresh one (an admin move on an old booking).
                if (! $b->manage_token_enc) {
                    $b->storeManageToken($rawToken ?: Str::random(40));
                }

                return true;
            });
        } catch (QueryException) {
            $moved = false;
        }
        if (! $moved) {
            return ['ok' => false, 'error' => 'taken'];
        }

        Cache::forget('cpi_slots_range');
        $b = $b->fresh();
        $this->notifyRescheduled($b, $old, $by);

        return ['ok' => true, 'booking' => $b];
    }

    public function cancel(CpiBooking $booking, string $by = 'visitor'): void
    {
        $booking->update(['status' => 'cancelled', 'sequence' => $booking->sequence + 1]);
        Cache::forget('cpi_slots_range');
        $c = $this->mailContext($booking);
        $team = config('services.contact.recipient');
        $icsAll = $this->ics($booking, 'CANCEL');

        $this->safely('cancel notice to visitor', fn () => Mail::to($booking->email, $booking->name)->send(CpiBookingMail::cancelled($booking, $c, 'visitor', $icsAll)));
        if ($team) {
            $this->safely('cancel notice to team', fn () => Mail::to($team)->send(CpiBookingMail::cancelled($booking, $c, 'team')));
        }
        foreach ($booking->staff as $u) {
            $this->safely('cancel notice to staff', fn () => Mail::to($u->email, $u->name)->send(CpiBookingMail::cancelled($booking, $c, 'staff', $this->ics($booking, 'CANCEL', [['name' => $u->name, 'email' => $u->email]]))));
        }
    }

    /**
     * Set the IPFlow staff invited to a booking. New people get an invite (calendar file + in-app notification); anyone
     * removed gets a cancellation for their copy of the event. The visitor is not emailed.
     *
     * @param  list<int>  $userIds
     * @return array{added: list<int>, removed: list<int>}
     */
    public function assignStaff(CpiBooking $b, array $userIds, ?User $by = null): array
    {
        $eligible = self::eligibleStaff()->pluck('id')->all();
        $wanted = array_values(array_unique(array_map('intval', array_intersect($userIds, $eligible))));
        $before = $b->staff()->pluck('users.id')->map(fn ($v) => (int) $v)->all();
        $added = array_values(array_diff($wanted, $before));
        $removed = array_values(array_diff($before, $wanted));
        if (! $added && ! $removed) {
            return ['added' => [], 'removed' => []];
        }

        $b->staff()->sync($wanted);
        $b->update(['sequence' => $b->sequence + 1]);   // the attendee list changed
        $b = $b->fresh();
        $c = $this->mailContext($b);

        foreach (User::whereIn('id', $added)->get() as $u) {
            $this->safely('staff invite', fn () => Mail::to($u->email, $u->name)->send(CpiBookingMail::staffInvite($b, $c, $this->ics($b))));
            \App\Support\Notifier::push($u->id, 'consultation_assigned', 'You were added to a consultation',
                "{$b->name} on ".$b->start_utc->copy()->setTimezone($c['tzCompany'])->format('D j M, g:i A T').'. The invite is in your email.', null, ['booking_id' => $b->id]);
        }
        foreach (User::whereIn('id', $removed)->get() as $u) {
            $this->safely('staff removal', fn () => Mail::to($u->email, $u->name)->send(CpiBookingMail::cancelled($b, $c, 'staff', $this->ics($b, 'CANCEL', [['name' => $u->name, 'email' => $u->email]]))));
        }

        return ['added' => $added, 'removed' => $removed];
    }

    /** IPFlow users who can be invited: active and not a client login. */
    public static function eligibleStaff()
    {
        return User::where('status', 'Active')->whereNotIn('role', User::CLIENT_ROLES)->where('email', 'not like', '%@example.invalid')->orderBy('name')->get(['id', 'name', 'email', 'role']);
    }

    private function notifyRescheduled(CpiBooking $b, \Carbon\CarbonInterface $old, string $by): void
    {
        $c = $this->mailContext($b);
        $team = config('services.contact.recipient');
        $ics = $this->ics($b);

        $this->safely('reschedule notice to visitor', fn () => Mail::to($b->email, $b->name)->send(CpiBookingMail::rescheduled($b, $c, 'visitor', $old, $ics, $by)));
        if ($team) {
            $this->safely('reschedule notice to team', fn () => Mail::to($team)->send(CpiBookingMail::rescheduled($b, $c, 'team', $old, $ics, $by)));
        }
        foreach ($b->staff as $u) {
            $this->safely('reschedule notice to staff', fn () => Mail::to($u->email, $u->name)->send(CpiBookingMail::rescheduled($b, $c, 'staff', $old, $ics, $by)));
        }
    }

    private function sendEmails(CpiBooking $b, string $token): void
    {
        $c = $this->mailContext($b, $token);
        $ics = $this->ics($b);
        $recipient = config('services.contact.recipient');
        $mailOk = true;

        try {
            Mail::to($b->email, $b->name)->send(CpiBookingMail::confirmation($b, $c, $ics));
        } catch (\Throwable $e) {
            $mailOk = false;
            Log::error('Booking confirmation email failed', ['error' => $e->getMessage()]);
        }

        try {
            if ($recipient) {
                Mail::to($recipient)->send(CpiBookingMail::notification($b, $c, $ics));
            } else {
                $mailOk = false;
            }
        } catch (\Throwable $e) {
            $mailOk = false;
            Log::error('Booking notification email failed', ['error' => $e->getMessage()]);
        }

        SiteEnquiry::where('type', 'booking')->where('payload->booking_id', (string) $b->id)->update(['mail_status' => $mailOk ? 'sent' : 'failed']);
    }

    private function safely(string $what, callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::error("Booking email failed ({$what})", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Calendar file for a booking. REQUEST creates or updates the event (SEQUENCE makes an update replace the old one);
     * CANCEL removes it. Attendees default to the visitor plus every invited staff member.
     *
     * @param  list<array{name: string, email: string}>|null  $attendees
     */
    public function ics(CpiBooking $b, string $method = 'REQUEST', ?array $attendees = null): string
    {
        $cfg = self::settings();
        $b->loadMissing('staff');
        $attendees ??= array_merge([['name' => $b->name, 'email' => $b->email]], $b->staff->map(fn ($u) => ['name' => $u->name, 'email' => $u->email])->all());
        $organizer = (string) (config('services.contact.recipient') ?: 'noreply@canpatentit.com');
        $join = ($cfg['meeting_url'] ?? '') !== '' ? $cfg['meeting_url'] : null;
        $fmt = fn (Carbon $c) => $c->copy()->utc()->format('Ymd\THis\Z');
        $esc = fn (string $t) => str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $t);
        $desc = 'Consultation with CanPatentIt.'.($join ? " Join: {$join}" : '').(trim((string) $b->topic) !== '' ? "\n\nTopic: ".trim((string) $b->topic) : '');

        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//CanPatentIt//Booking//EN', 'CALSCALE:GREGORIAN', 'METHOD:'.$method, 'BEGIN:VEVENT',
            "UID:booking-{$b->id}@canpatentit.com", 'SEQUENCE:'.(int) $b->sequence, 'DTSTAMP:'.$fmt(now()), 'DTSTART:'.$fmt($b->start_utc), 'DTEND:'.$fmt($b->end_utc),
            'SUMMARY:CanPatentIt consultation', 'DESCRIPTION:'.$esc($desc), 'LOCATION:'.$esc($join ?: 'Online'), 'STATUS:'.($method === 'CANCEL' ? 'CANCELLED' : 'CONFIRMED'),
            'ORGANIZER;CN=CanPatentIt:mailto:'.$organizer];
        if ($join) {
            $lines[] = 'URL:'.$join;
        }
        foreach ($attendees as $a) {
            $lines[] = 'ATTENDEE;CN='.str_replace([',', ';', ':', '"'], ' ', $a['name']).';ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:'.$a['email'];
        }
        array_push($lines, 'END:VEVENT', 'END:VCALENDAR');

        // RFC 5545: lines longer than 75 octets are folded onto continuation lines that start with a space.
        return implode("\r\n", array_map(fn (string $l) => rtrim(chunk_split($l, 73, "\r\n "), "\r\n "), $lines))."\r\n";
    }
}
