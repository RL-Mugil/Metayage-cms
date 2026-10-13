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

        $result = $this->service->book($data, $request->ip(), $request->userAgent());
        if (! $result) {
            return response()->json(['message' => 'That time is no longer available.'], 409);
        }

        return response()->json(['message' => 'Booked.', 'start' => $result['booking']->start_utc->utc()->toIso8601String()], 201);
    }

    /** Email links open this page; cancelling needs a deliberate POST so mail scanners cannot cancel by prefetching. */
    public function cancelPage(int $id, string $token): Response
    {
        $b = $this->match($id, $token);
        $body = $b && $b->status === 'confirmed'
            ? '<h1>Cancel your consultation?</h1><p>'.e($b->start_utc->utc()->format('D j M Y, H:i')).' UTC</p><form method="post"><button type="submit">Yes, cancel it</button></form>'
            : '<h1>Nothing to cancel</h1><p>This booking is already cancelled or the link is not valid.</p>';

        return response('<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Consultation</title><body style="font-family:system-ui;max-width:480px;margin:15vh auto;padding:0 20px">'.$body.'<p><a href="https://canpatentit.com">canpatentit.com</a></p></body>', 200)->header('Content-Type', 'text/html; charset=utf-8');
    }

    public function cancel(int $id, string $token): Response
    {
        $b = $this->match($id, $token);
        if ($b && $b->status === 'confirmed') {
            $this->service->cancel($b);
            $body = '<h1>Cancelled</h1><p>Your consultation has been cancelled. You can book another time at any point.</p>';
        } else {
            $body = '<h1>Nothing to cancel</h1><p>This booking is already cancelled or the link is not valid.</p>';
        }

        return response('<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Consultation</title><body style="font-family:system-ui;max-width:480px;margin:15vh auto;padding:0 20px">'.$body.'<p><a href="https://canpatentit.com/book">Book a new time</a></p></body>', 200)->header('Content-Type', 'text/html; charset=utf-8');
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
            'bookings' => CpiBooking::orderBy('start_utc')->where('start_utc', '>=', now()->subDays(30))->limit(300)->get(),
            'rules' => CpiAvailabilityRule::orderBy('weekday')->orderBy('start_time')->get(),
            'blackouts' => CpiBlackout::where('day', '>=', now()->subDay()->toDateString())->orderBy('day')->get(),
            'settings' => CpiBookingService::settings(),
        ]);
    }

    public function adminCancel(Request $request, int $id): JsonResponse
    {
        Gate::authorize('manage-site');
        $b = CpiBooking::findOrFail($id);
        if ($b->status === 'confirmed') {
            $this->service->cancel($b);
            AuditLog::create(['user_id' => $request->user()->id, 'action' => 'cpi_booking_cancel', 'subject_type' => CpiBooking::class, 'subject_id' => $b->id, 'metadata' => [], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
        }

        return response()->json($b->fresh());
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

        return $this->adminIndex();
    }
}
