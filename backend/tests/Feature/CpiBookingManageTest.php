<?php

namespace Tests\Feature;

use App\Mail\CpiBookingMail;
use App\Models\CpiBooking;
use App\Models\User;
use App\Services\CpiBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CpiBookingManageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.contact.recipient' => 'inbox@example.com', 'services.turnstile.secret_key' => null, 'mail.default' => 'array']);
        Cache::flush();
    }

    /** @return list<string> the first open slots (UTC ISO) */
    private function slots(int $count = 3): array
    {
        $slots = (new CpiBookingService())->slotsInRange(true);
        $this->assertGreaterThanOrEqual($count, count($slots), 'default availability should offer slots');

        return array_slice($slots, 0, $count);
    }

    /** Book through the service so the test knows the visitor's token. @return array{0: CpiBooking, 1: string} */
    private function book(string $slot, array $extra = []): array
    {
        $r = (new CpiBookingService())->book(array_merge(['start' => $slot, 'name' => 'Asha Rao', 'email' => 'asha@example.com', 'timezone' => 'America/New_York', 'topic' => 'A vision model'], $extra), '127.0.0.1', 'test');
        $this->assertNotNull($r);

        return [$r['booking'], $r['token']];
    }

    private function adminHeaders(): array
    {
        User::create(['name' => 'Admin', 'email' => 'root@test.local', 'password' => bcrypt('secret-pass-1'), 'role' => 'super_admin', 'status' => 'Active']);

        return ['Authorization' => 'Bearer '.$this->postJson('/api/cpi-admin/login', ['email' => 'root@test.local', 'password' => 'secret-pass-1'])->json('token')];
    }

    private function staff(string $name, string $role = 'associate'): User
    {
        return User::create(['name' => $name, 'email' => strtolower($name).'@firm.test', 'password' => bcrypt('x-secret-1'), 'role' => $role, 'status' => 'Active']);
    }

    // ---- visitor side ----------------------------------------------------------------------------------------------

    public function test_the_range_endpoint_lists_every_open_slot_in_utc(): void
    {
        $r = $this->getJson('/api/public/cpi/range')->assertOk()->assertJsonStructure(['timezone', 'slot_minutes', 'slots'])->json();
        $this->assertNotEmpty($r['slots']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $r['slots'][0]);
    }

    public function test_the_booking_stores_a_valid_visitor_zone_and_drops_an_invalid_one(): void
    {
        [$a] = $this->book($this->slots(2)[0], ['timezone' => 'Asia/Tokyo']);
        $this->assertSame('Asia/Tokyo', $a->visitor_timezone);

        [$slot] = array_slice($this->slots(3), 1, 1);
        $this->postJson('/api/public/cpi/book', ['start' => $slot, 'name' => 'Bob Lee', 'email' => 'bob@example.com', 'timezone' => 'Not/AZone', 'elapsed_ms' => 9000])->assertCreated();
        $this->assertNull(CpiBooking::where('email', 'bob@example.com')->first()->visitor_timezone);
    }

    public function test_the_manage_endpoint_needs_the_right_token_and_shows_the_booking_in_the_visitors_zone(): void
    {
        [$b, $token] = $this->book($this->slots()[0]);

        $this->getJson("/api/public/cpi/manage/{$b->id}/wrong-token")->assertNotFound();
        $r = $this->getJson("/api/public/cpi/manage/{$b->id}/{$token}")->assertOk()->json();

        $this->assertSame('America/New_York', $r['timezone']);
        $this->assertTrue($r['can_reschedule']);
        $this->assertSame('confirmed', $r['status']);
    }

    public function test_a_visitor_can_reschedule_to_an_open_slot_and_everyone_is_told_with_an_updated_invite(): void
    {
        [$s1, $s2] = $this->slots();
        [$b, $token] = $this->book($s1);
        $mine = $this->staff('Priya');
        $b->staff()->attach($mine->id);
        Mail::fake();

        $this->postJson("/api/public/cpi/manage/{$b->id}/{$token}/reschedule", ['start' => $s2])->assertOk()->assertJsonPath('message', 'Rescheduled.');

        $b->refresh();
        $this->assertSame($s2, $b->start_utc->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame(1, $b->sequence);
        $this->assertSame(1, $b->reschedule_count);
        $this->assertSame('visitor', $b->last_rescheduled_by);
        Mail::assertSent(CpiBookingMail::class, fn ($m) => $m->kind === 'rescheduled-visitor' && $m->hasTo('asha@example.com') && str_contains($m->icsData, 'SEQUENCE:1') && str_contains($m->htmlBody, 'text-decoration:line-through'));
        Mail::assertSent(CpiBookingMail::class, fn ($m) => $m->kind === 'rescheduled-team' && $m->hasTo('inbox@example.com'));
        Mail::assertSent(CpiBookingMail::class, fn ($m) => $m->kind === 'rescheduled-staff' && $m->hasTo('priya@firm.test'));
        // the manage link in later emails still works because the token was kept
        $this->getJson("/api/public/cpi/manage/{$b->id}/{$token}")->assertOk();
    }

    public function test_a_visitor_cannot_move_to_a_taken_or_closed_time_or_inside_the_cut_off(): void
    {
        [$s1, $s2, $s3] = $this->slots();
        [$b, $token] = $this->book($s1);
        $this->book($s2, ['email' => 'other@example.com']);

        $this->postJson("/api/public/cpi/manage/{$b->id}/{$token}/reschedule", ['start' => $s2])->assertStatus(422)->assertJsonPath('code', 'unavailable');   // taken slots are no longer offered
        $this->postJson("/api/public/cpi/manage/{$b->id}/{$token}/reschedule", ['start' => CarbonImmutable::parse($s3)->addMinutes(7)->format('Y-m-d\TH:i:s\Z')])->assertStatus(422)->assertJsonPath('code', 'unavailable');

        $b->update(['start_utc' => now()->addHours(5), 'end_utc' => now()->addHours(5)->addMinutes(30)]);
        $this->postJson("/api/public/cpi/manage/{$b->id}/{$token}/reschedule", ['start' => $s3])->assertStatus(422)->assertJsonPath('code', 'too_late');
        $this->getJson("/api/public/cpi/manage/{$b->id}/{$token}")->assertJsonPath('can_reschedule', false);
    }

    public function test_a_visitor_can_cancel_and_the_visitor_team_and_staff_get_a_cancellation(): void
    {
        [$s1] = $this->slots();
        [$b, $token] = $this->book($s1);
        $b->staff()->attach($this->staff('Priya')->id);
        Mail::fake();

        $this->postJson("/api/public/cpi/manage/{$b->id}/{$token}/cancel")->assertOk();

        $this->assertSame('cancelled', $b->fresh()->status);
        Mail::assertSent(CpiBookingMail::class, fn ($m) => $m->kind === 'cancelled-visitor' && $m->hasTo('asha@example.com') && $m->icsMethod === 'CANCEL' && str_contains($m->icsData, 'STATUS:CANCELLED'));
        Mail::assertSent(CpiBookingMail::class, fn ($m) => $m->kind === 'cancelled-team');
        Mail::assertSent(CpiBookingMail::class, fn ($m) => $m->kind === 'cancelled-staff' && $m->hasTo('priya@firm.test'));
        $this->postJson("/api/public/cpi/manage/{$b->id}/{$token}/cancel")->assertNotFound();
        $this->assertContains($s1, (new CpiBookingService())->slotsInRange(true), 'the slot is free again');
    }

    public function test_old_cancel_links_open_the_branded_manage_page_instead_of_a_dark_utc_page(): void
    {
        [$b, $token] = $this->book($this->slots()[0]);

        $this->get("/api/public/cpi/cancel/{$b->id}/{$token}")->assertRedirectContains('https://canpatentit.com/booking/manage?action=cancel#'.$b->id.'.');
        $this->get("/api/public/cpi/cancel/{$b->id}/bad")->assertOk()->assertSee('color-scheme', false)->assertSee('Nothing to cancel');
    }

    // ---- admin side ------------------------------------------------------------------------------------------------

    public function test_admin_can_reschedule_to_any_time_and_it_is_audited(): void
    {
        [$s1] = $this->slots();
        [$b] = $this->book($s1);
        $h = $this->adminHeaders();
        Mail::fake();
        $custom = CarbonImmutable::now('UTC')->addDays(2)->setTime(3, 17)->format('Y-m-d\TH:i:s\Z');

        $this->postJson("/api/cpi-admin/bookings/{$b->id}/reschedule", ['start' => $custom], $h)->assertStatus(422);
        $this->postJson("/api/cpi-admin/bookings/{$b->id}/reschedule", ['start' => $custom, 'any_time' => true], $h)->assertOk();

        $this->assertSame($custom, $b->fresh()->start_utc->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('admin', $b->fresh()->last_rescheduled_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cpi_booking_reschedule', 'subject_id' => $b->id]);
        Mail::assertSent(CpiBookingMail::class, fn ($m) => $m->kind === 'rescheduled-visitor');
    }

    public function test_admin_assigns_several_staff_who_get_invites_and_removed_staff_get_a_cancel(): void
    {
        [$b] = $this->book($this->slots()[0]);
        $h = $this->adminHeaders();
        $priya = $this->staff('Priya');
        $karthik = $this->staff('Karthik', 'manager');
        $client = $this->staff('Client', 'client');
        Mail::fake();

        $options = $this->getJson('/api/cpi-admin/booking-options', $h)->assertOk()->json();
        $eligible = collect($options['staff'])->pluck('email');
        $this->assertTrue($eligible->contains('priya@firm.test'));
        $this->assertFalse($eligible->contains('client@firm.test'), 'client logins cannot be invited');

        $this->putJson("/api/cpi-admin/bookings/{$b->id}/staff", ['user_ids' => [$priya->id, $karthik->id, $client->id]], $h)->assertOk()->assertJsonCount(2, 'staff');

        Mail::assertSent(CpiBookingMail::class, fn ($m) => $m->kind === 'staff-invite' && $m->hasTo('priya@firm.test') && str_contains(str_replace("
 ", '', $m->icsData), 'mailto:karthik@firm.test'));
        Mail::assertSent(CpiBookingMail::class, fn ($m) => $m->kind === 'staff-invite' && $m->hasTo('karthik@firm.test'));
        Mail::assertNotSent(CpiBookingMail::class, fn ($m) => $m->hasTo('asha@example.com'));
        $this->assertSame(2, DB::table('ip_notifications')->where('type', 'consultation_assigned')->count());

        $this->putJson("/api/cpi-admin/bookings/{$b->id}/staff", ['user_ids' => [$karthik->id]], $h)->assertOk()->assertJsonCount(1, 'staff');
        Mail::assertSent(CpiBookingMail::class, fn ($m) => $m->kind === 'cancelled-staff' && $m->hasTo('priya@firm.test') && $m->icsMethod === 'CANCEL');
    }

    public function test_the_join_link_is_saved_only_when_it_is_https_and_appears_in_the_invite(): void
    {
        $h = $this->adminHeaders();
        $payload = fn (string $url) => ['rules' => [['weekday' => 1, 'start_time' => '10:00', 'end_time' => '17:00']], 'blackouts' => [],
            'settings' => ['slot_minutes' => 30, 'buffer_minutes' => 0, 'min_notice_hours' => 12, 'horizon_days' => 21, 'timezone' => 'Asia/Kolkata', 'meeting_url' => $url, 'visitor_reschedule_hours' => 12]];

        $this->putJson('/api/cpi-admin/availability', $payload('http://insecure.example/room'), $h)->assertStatus(422);
        $this->putJson('/api/cpi-admin/availability', $payload('https://meet.example.com/abc-defg'), $h)->assertOk()->assertJsonPath('settings.meeting_url', 'https://meet.example.com/abc-defg');

        $b = new CpiBooking(['name' => 'A B', 'email' => 'a@b.test', 'start_utc' => now()->addDay(), 'end_utc' => now()->addDay()->addMinutes(30)]);
        $b->id = 5;
        $b->setRelation('staff', collect());
        $this->assertStringContainsString('URL:https://meet.example.com/abc-defg', (new CpiBookingService())->ics($b));
    }
}
