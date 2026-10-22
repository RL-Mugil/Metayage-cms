<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CpiAvailabilityRule;
use App\Models\CpiBlackout;
use App\Models\CpiBooking;
use App\Services\CpiBookingService;
use App\Services\TurnstileVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CpiBookingController extends Controller
{
    public function __construct(private CpiBookingService $service)
    {
    }

    // ---- Public ----

    public function days(): JsonResponse
    {
        return response()->json(['timezone' => CpiBookingService::settings()['timezone'], 'days' => $this->service->openDays()]);
    }

    public function slots(Request $request): JsonResponse
    {
        $date = (string) $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];

        return response()->json(['slots' => $this->service->slotsForDate($date)]);
    }

    /** Every open slot in the window (UTC), so the page can group them by the visitor's own time zone. */
    public function range(): JsonResponse
    {
        $cfg = CpiBookingService::settings();

        return response()->json(['timezone' => $cfg['timezone'], 'slot_minutes' => $cfg['slot_minutes'], 'slots' => $this->service->slotsInRange()]);
    }

    public function book(Request $request, TurnstileVerificationService $turnstile): JsonResponse
    {
        $data = $request->validate([
            'start' => ['required', 'date'],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:150'],
            'organization' => ['nullable', 'string', 'max:150'],
            'topic' => ['nullable', 'string', 'max:1000'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'website' => ['nullable', 'string', 'max:255'],
            'elapsed_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        $tooFast = isset($data['elapsed_ms']) && (int) $data['elapsed_ms'] < 2000;
        if (! empty($data['website']) || $tooFast) {
            return response()->json(['message' => 'Thank you.']);
        }
        if (! $turnstile->verify($request)) {
            return response()->json(['message' => 'Verification failed. Please try again.'], 422);
        }

        // Strip CR/LF so input can never inject mail headers.
        $clean = fn (?string $v): string => trim(preg_replace('/[\r\n]+/', ' ', (string) $v));
        $data['name'] = $clean($data['name']);
        $data['organization'] = $clean($data['organization'] ?? '') ?: null;
        $data['timezone'] = $this->validZone($data['timezone'] ?? null);

        $result = $this->service->book($data, $request->ip(), $request->userAgent());
        if (! $result) {
            return response()->json(['message' => 'That time is no longer available.'], 409);
        }

        return response()->json(['message' => 'Booked.', 'start' => $result['booking']->start_utc->utc()->toIso8601String()], 201);
    }

    private function validZone(?string $tz): ?string
    {
        try {
            return $tz ? (new \DateTimeZone($tz))->getName() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ---- Visitor: manage their own booking (the emailed link carries the token) ----

    public function manage(int $id, string $token): JsonResponse
    {
        $b = $this->match($id, $token);
        if (! $b) {
            return response()->json(['message' => 'This link is not valid.'], 404);
        }
        $cfg = CpiBookingService::settings();
        $deadline = $b->start_utc->copy()->subHours((int) $cfg['visitor_reschedule_hours']);

        return response()->json([
            'status' => $b->status, 'name' => $b->name, 'start' => $b->start_utc->utc()->toIso8601String(), 'end' => $b->end_utc->utc()->toIso8601String(),
            'timezone' => $b->visitorZone($cfg['timezone']), 'company_timezone' => $cfg['timezone'],
            'can_reschedule' => $b->status === 'confirmed' && ! $deadline->isPast(), 'reschedule_deadline' => $deadline->utc()->toIso8601String(),
            'reschedule_hours' => (int) $cfg['visitor_reschedule_hours'], 'join_url' => $b->status === 'confirmed' && ($cfg['meeting_url'] ?? '') !== '' ? $cfg['meeting_url'] : null,
            'slot_minutes' => $cfg['slot_minutes'],
        ]);
    }

    public function rescheduleByVisitor(Request $request, int $id, string $token): JsonResponse
    {
        $b = $this->match($id, $token);
        if (! $b) {
            return response()->json(['message' => 'This link is not valid.'], 404);
        }
        $start = (string) $request->validate(['start' => ['required', 'date']])['start'];

        return $this->rescheduleResponse($this->service->reschedule($b, $start, 'visitor', $token));
    }

    public function cancelByVisitor(int $id, string $token): JsonResponse
    {
        $b = $this->match($id, $token);
        if (! $b || $b->status !== 'confirmed') {
            return response()->json(['message' => 'There is nothing to cancel: this booking is already cancelled or the link is not valid.'], 404);
        }
        $this->service->cancel($b, 'visitor');

        return response()->json(['message' => 'Cancelled.']);
    }

    private function rescheduleResponse(array $r): JsonResponse
    {
        if ($r['ok']) {
            return response()->json(['message' => 'Rescheduled.', 'start' => $r['booking']->start_utc->utc()->toIso8601String(), 'end' => $r['booking']->end_utc->utc()->toIso8601String()]);
        }
        $map = [
            'cancelled' => [409, 'This booking has been cancelled.'],
            'too_late' => [422, 'This call is too close to move online. Please email us and we will sort it out.'],
            'unavailable' => [422, 'That time is not available. Please choose another.'],
            'taken' => [409, 'Sorry, that time was just taken. Please choose another.'],
        ];
        [$status, $message] = $map[$r['error']] ?? [422, 'The booking could not be moved.'];

        return response()->json(['message' => $message, 'code' => $r['error']], $status);
    }

    /**
     * Older emails link straight to these pages. They now send people to the booking-management page on the website
     * (light, on-brand, in the visitor's own time zone). Cancelling still needs a deliberate POST.
     */
    public function cancelPage(int $id, string $token): \Symfony\Component\HttpFoundation\Response
    {
        $b = $this->match($id, $token);

        return $b ? redirect()->away('https://canpatentit.com/booking/manage?action=cancel#'.$id.'.'.$token) : $this->plainPage('Nothing to cancel', 'This booking is already cancelled or the link is not valid.', 'https://canpatentit.com/book', 'Book a new time');
    }

    public function cancel(int $id, string $token): Response
    {
        $b = $this->match($id, $token);
        if ($b && $b->status === 'confirmed') {
            $this->service->cancel($b, 'visitor');

            return $this->plainPage('Cancelled', 'Your consultation has been cancelled. You can book another time at any point.', 'https://canpatentit.com/book', 'Book a new time');
        }

        return $this->plainPage('Nothing to cancel', 'This booking is already cancelled or the link is not valid.', 'https://canpatentit.com/book', 'Book a new time');
    }

    /** Minimal, always-light page for the rare fallback cases (it never inherits a dark mode). */
    private function plainPage(string $title, string $text, string $url, string $label): Response
    {
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>Consultation | CanPatentIt</title></head>'
            .'<body style="margin:0;background:#eef2f5;color:#212529;font-family:Segoe UI,Helvetica,Arial,sans-serif"><div style="max-width:480px;margin:12vh auto;padding:32px;background:#ffffff;border-radius:14px;box-shadow:0 8px 30px rgba(20,60,90,.1)">'
            .'<img src="https://canpatentit.com/assets/logo.png" width="140" alt="CanPatentIt" style="display:block;margin-bottom:18px"><h1 style="margin:0 0 10px;font-size:24px">'.e($title).'</h1><p style="margin:0 0 20px;line-height:1.6;color:#5c646c">'.e($text).'</p>'
            .'<a href="'.e($url).'" style="display:inline-block;background:#ecb439;color:#212529;border-radius:8px;padding:12px 22px;font-weight:700;text-decoration:none">'.e($label).'</a></div></body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }

    private function match(int $id, string $token): ?CpiBooking
    {
        $b = CpiBooking::find($id);

        return $b && hash_equals($b->manage_token_hash, hash('sha256', $token)) ? $b : null;
    }

    // ---- Admin ----

    public function adminIndex(): JsonResponse
    {
        Gate::authorize('manage-site');

        return response()->json([
            'bookings' => CpiBooking::with('staff:id,name,email')->orderBy('start_utc')->where('start_utc', '>=', now()->subDays(30))->limit(300)->get(),
            'rules' => CpiAvailabilityRule::orderBy('weekday')->orderBy('start_time')->get(),
            'blackouts' => CpiBlackout::where('day', '>=', now()->subDay()->toDateString())->orderBy('day')->get(),
            'settings' => CpiBookingService::settings(),
        ]);
    }

    /** IPFlow users who can be invited to a call, and the slots open for rescheduling. */
    public function adminOptions(): JsonResponse
    {
        Gate::authorize('manage-site');

        return response()->json(['staff' => CpiBookingService::eligibleStaff(), 'slots' => $this->service->slotsInRange(true), 'timezone' => CpiBookingService::settings()['timezone']]);
    }

    public function adminCancel(Request $request, int $id): JsonResponse
    {
        Gate::authorize('manage-site');
        $b = CpiBooking::findOrFail($id);
        if ($b->status === 'confirmed') {
            $this->service->cancel($b, 'admin');
            AuditLog::create(['user_id' => $request->user()->id, 'action' => 'cpi_booking_cancel', 'subject_type' => CpiBooking::class, 'subject_id' => $b->id, 'metadata' => [], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
        }

        return response()->json($b->fresh()->load('staff:id,name,email'));
    }

    public function adminReschedule(Request $request, int $id): JsonResponse
    {
        Gate::authorize('manage-site');
        $b = CpiBooking::findOrFail($id);
        $data = $request->validate(['start' => ['required', 'date'], 'any_time' => ['sometimes', 'boolean']]);
        $old = $b->start_utc->utc()->toIso8601String();

        $r = $this->service->reschedule($b, $data['start'], 'admin', null, (bool) ($data['any_time'] ?? false));
        if ($r['ok']) {
            AuditLog::create(['user_id' => $request->user()->id, 'action' => 'cpi_booking_reschedule', 'subject_type' => CpiBooking::class, 'subject_id' => $b->id,
                'metadata' => ['from' => $old, 'to' => $r['booking']->start_utc->utc()->toIso8601String(), 'any_time' => (bool) ($data['any_time'] ?? false)], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
        }

        return $r['ok'] ? response()->json($r['booking']->load('staff:id,name,email')) : $this->rescheduleResponse($r);
    }

    public function adminAssignStaff(Request $request, int $id): JsonResponse
    {
        Gate::authorize('manage-site');
        $b = CpiBooking::findOrFail($id);
        $data = $request->validate(['user_ids' => ['present', 'array', 'max:20'], 'user_ids.*' => ['integer']]);

        $result = $this->service->assignStaff($b, $data['user_ids'], $request->user());
        if ($result['added'] || $result['removed']) {
            AuditLog::create(['user_id' => $request->user()->id, 'action' => 'cpi_booking_staff', 'subject_type' => CpiBooking::class, 'subject_id' => $b->id, 'metadata' => $result, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
        }

        return response()->json($b->fresh()->load('staff:id,name,email'));
    }

    /** Replaces weekly hours, blackout dates and slot settings in one transaction. */
    public function saveAvailability(Request $request): JsonResponse
    {
        Gate::authorize('manage-site');
        $data = $request->validate([
            'rules' => ['present', 'array', 'max:40'],
            'rules.*.weekday' => ['required', 'integer', 'between:0,6'],
            'rules.*.start_time' => ['required', 'date_format:H:i'],
            'rules.*.end_time' => ['required', 'date_format:H:i', 'after:rules.*.start_time'],
            'blackouts' => ['present', 'array', 'max:200'],
            'blackouts.*.day' => ['required', 'date_format:Y-m-d'],
            'blackouts.*.note' => ['nullable', 'string', 'max:120'],
            'settings.slot_minutes' => ['required', 'integer', 'between:15,120'],
            'settings.buffer_minutes' => ['required', 'integer', 'between:0,60'],
            'settings.min_notice_hours' => ['required', 'integer', 'between:0,168'],
            'settings.horizon_days' => ['required', 'integer', 'between:1,90'],
            'settings.timezone' => ['required', 'string', 'timezone:all'],
            'settings.meeting_url' => ['nullable', 'string', 'max:300', 'url:https'],
            'settings.visitor_reschedule_hours' => ['nullable', 'integer', 'between:0,168'],
        ]);

        DB::transaction(function () use ($data) {
            CpiAvailabilityRule::query()->delete();
            foreach ($data['rules'] as $r) {
                CpiAvailabilityRule::create(['weekday' => $r['weekday'], 'start_time' => $r['start_time'], 'end_time' => $r['end_time']]);
            }
            CpiBlackout::query()->delete();
            foreach ($data['blackouts'] as $b) {
                CpiBlackout::create(['day' => $b['day'], 'note' => $b['note'] ?? null]);
            }
            CpiBookingService::saveSettings($data['settings']);
        });
        \Illuminate\Support\Facades\Cache::forget('cpi_slots_range');

        return $this->adminIndex();
    }
}
