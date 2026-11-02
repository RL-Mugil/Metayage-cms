<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CpiBooking;
use App\Models\Integration;
use App\Models\OpsIncident;
use App\Services\CpiBookingService;
use App\Services\OpsIncidentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Regression tests for the findings of the code review of the booking, Zoho poll and incident-recording changes. */
class ReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.contact.recipient' => 'inbox@example.com', 'services.turnstile.secret_key' => null, 'mail.default' => 'array']);
        Cache::flush();
    }

    private function book(string $slot, array $extra = []): array
    {
        $r = (new CpiBookingService())->book(array_merge(['start' => $slot, 'name' => 'Asha Rao', 'email' => 'asha@example.com', 'timezone' => 'Asia/Kolkata'], $extra), '127.0.0.1', 'test');
        $this->assertNotNull($r);

        return [$r['booking'], $r['token']];
    }

    public function test_a_zoho_outage_keeps_failing_the_poll_so_the_incident_is_not_resolved_by_a_successful_exit(): void
    {
        Http::preventStrayRequests();
        Integration::create(['slug' => 'zoho', 'name' => 'Zoho Books', 'description' => 'Accounting', 'category' => 'Finance', 'initials' => 'ZB', 'color' => 'red', 'connected' => true,
            'config' => ['client_id' => 'test', 'client_secret' => encrypt('secret'), 'refresh_token' => encrypt('refresh'), 'organization_id' => '123', 'region' => 'in']]);
        Cache::put('zoho_access_token:in:123', 'fake-token', 86400);
        Http::fake(fn () => throw new ConnectionException('timed out'));

        for ($i = 1; $i <= 5; $i++) {
            $this->artisan('zoho:poll')->assertSuccessful();   // a blip: not a failure yet
        }
        $this->artisan('zoho:poll')->assertFailed();            // the sixth in a row is an outage
        $this->artisan('zoho:poll')->assertFailed();            // and it stays one for as long as it lasts
    }

    public function test_a_very_long_dedupe_key_is_still_recorded(): void
    {
        $service = app(OpsIncidentService::class);
        $key = 'job|'.str_repeat('App\\Jobs\\Namespaced\\', 20);

        $first = $service->record($key, 'Long key', 'm', 'i', 'a', 'tech');
        $second = $service->record($key, 'Long key', 'm', 'i', 'a', 'tech');

        $this->assertLessThanOrEqual(191, mb_strlen($first->fingerprint));
        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, (int) OpsIncident::find($first->id)->occurrences);
    }

    public function test_repeats_are_counted_atomically_and_a_lost_insert_race_counts_against_the_winner(): void
    {
        $service = app(OpsIncidentService::class);
        $service->record('race|one', 'Race', 'm', 'i', 'a', null);
        // Simulate the second worker: it checked before the first insert landed, so it tries to create and collides.
        $rows = OpsIncident::where('fingerprint', 'race|one')->count();
        $service->record('race|one', 'Race', 'm', 'i', 'a', null);
        $service->record('race|one', 'Race', 'm', 'i', 'a', null);

        $this->assertSame(1, $rows);
        $this->assertSame(1, OpsIncident::where('fingerprint', 'race|one')->count());
        $this->assertSame(3, (int) OpsIncident::where('fingerprint', 'race|one')->value('occurrences'));
    }

    public function test_booking_a_slot_drops_the_cached_slot_list(): void
    {
        $slots = (new CpiBookingService())->slotsInRange(true);
        Cache::put('cpi_slots_range', $slots, 45);

        $this->book($slots[0]);

        $this->assertFalse(Cache::has('cpi_slots_range'));
        $this->assertNotContains($slots[0], (new CpiBookingService())->slotsInRange());
    }

    public function test_the_range_matches_the_day_by_day_slots_and_uses_a_handful_of_queries(): void
    {
        $service = new CpiBookingService();
        $cfg = CpiBookingService::settings();
        $this->book($service->slotsInRange(true)[2]);   // a taken slot must be left out in both

        $expected = [];
        $today = CarbonImmutable::now($cfg['timezone'])->startOfDay();
        for ($i = 0; $i <= $cfg['horizon_days']; $i++) {
            $expected = array_merge($expected, $service->slotsForDate($today->addDays($i)->format('Y-m-d')));
        }
        sort($expected);

        DB::enableQueryLog();
        $range = $service->slotsInRange(true);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(array_values(array_unique($expected)), $range);
        $this->assertLessThanOrEqual(5, $queries, 'one set of queries for the whole window, not one per day');
    }

    public function test_a_reschedule_that_lost_the_race_to_a_cancel_is_refused(): void
    {
        Mail::fake();
        $slots = (new CpiBookingService())->slotsInRange(true);
        [$booking] = $this->book($slots[0]);
        $stale = CpiBooking::find($booking->id);                       // what the reschedule request loaded
        CpiBooking::whereKey($booking->id)->update(['status' => 'cancelled']);   // the cancel that landed meanwhile

        $r = (new CpiBookingService())->reschedule($stale, $slots[3], 'admin', null, true);

        $this->assertFalse($r['ok']);
        $this->assertSame('cancelled', $r['error']);
        $this->assertSame($slots[0], CpiBooking::find($booking->id)->start_utc->utc()->format('Y-m-d\TH:i:s\Z'), 'the cancelled booking must not be moved');
    }

    public function test_cancelling_twice_emails_once_and_audits_once(): void
    {
        Mail::fake();
        [$booking] = $this->book((new CpiBookingService())->slotsInRange(true)[0]);
        $service = new CpiBookingService();

        $service->cancel($booking, 'visitor', ['ip' => '1.2.3.4', 'ua' => 'test']);
        $sent = count(Mail::sent(\App\Mail\CpiBookingMail::class));
        $service->cancel(CpiBooking::find($booking->id), 'admin', ['user_id' => null]);

        $this->assertSame($sent, count(Mail::sent(\App\Mail\CpiBookingMail::class)));
        $this->assertSame(1, AuditLog::where('action', 'cpi_booking_cancel')->where('subject_id', $booking->id)->count());
    }

    public function test_an_admin_move_of_an_old_booking_keeps_the_visitors_earlier_links_working(): void
    {
        Mail::fake();
        $slots = (new CpiBookingService())->slotsInRange(true);
        [$booking, $token] = $this->book($slots[0]);
        CpiBooking::whereKey($booking->id)->update(['manage_token_enc' => null]);   // a booking made before the token was stored
        $hash = CpiBooking::find($booking->id)->manage_token_hash;

        $r = (new CpiBookingService())->reschedule(CpiBooking::find($booking->id), $slots[3], 'admin', null, true);

        $this->assertTrue($r['ok']);
        $this->assertSame($hash, CpiBooking::find($booking->id)->manage_token_hash);
        $this->getJson("/api/public/cpi/manage/{$booking->id}/{$token}")->assertOk();
    }

    public function test_visitor_actions_leave_an_audit_trail(): void
    {
        Mail::fake();
        $slots = (new CpiBookingService())->slotsInRange(true);
        [$booking, $token] = $this->book($slots[0]);

        $this->postJson("/api/public/cpi/manage/{$booking->id}/{$token}/reschedule", ['start' => $slots[3]])->assertOk();
        $this->postJson("/api/public/cpi/manage/{$booking->id}/{$token}/cancel")->assertOk();

        foreach (['cpi_booking_create', 'cpi_booking_reschedule', 'cpi_booking_cancel'] as $action) {
            $this->assertSame(1, AuditLog::where('action', $action)->where('subject_id', $booking->id)->count(), $action);
        }
    }

    public function test_calendar_lines_fold_on_whole_characters_and_keep_trailing_spaces(): void
    {
        Mail::fake();
        $name = str_repeat('Ananya Ñandiné ', 5).'देवनागरी';
        [$booking] = $this->book((new CpiBookingService())->slotsInRange(true)[0], ['name' => $name, 'topic' => str_repeat('தமிழ் ', 12)]);

        $ics = (new CpiBookingService())->ics($booking);

        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), 'physical line over 75 octets');
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'), 'a fold cut a multi-byte character: '.bin2hex($line));
        }
        $unfolded = str_replace("\r\n ", '', $ics);
        $this->assertTrue(mb_check_encoding($unfolded, 'UTF-8'));
        $this->assertStringContainsString('देवनागरी', $unfolded);
    }
}
