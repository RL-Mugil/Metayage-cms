<?php

namespace Tests\Feature;

use App\Mail\WeeklyDigestMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The Monday digest is off by default, obeys the firm switch and each person's opt-out. */
class WeeklyDigestTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $email, ?array $prefs = null): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $email, 'password' => bcrypt('password'), 'role' => $role, 'status' => 'Active', 'notification_prefs' => $prefs]);
    }

    public function test_digest_sends_nothing_until_switched_on(): void
    {
        Mail::fake();
        $this->user('partner', 'p@digest.test');
        $this->artisan('digest:weekly')->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_only_a_super_admin_switches_it_on_and_opted_out_people_are_skipped(): void
    {
        Mail::fake();
        $admin = $this->user('super_admin', 'a@digest.test');
        $partner = $this->user('partner', 'p@digest.test');
        $this->user('manager', 'm@digest.test', ['weeklyDigest' => false]);
        $this->user('associate', 'x@digest.test');

        Sanctum::actingAs($partner);
        $this->putJson('/api/settings/switches', ['weekly_digest_enabled' => true])->assertForbidden();
        $this->getJson('/api/settings/switches')->assertOk()->assertJson(['weekly_digest_enabled' => false, 'leaderboard_enabled' => false]);

        Sanctum::actingAs($admin);
        $this->putJson('/api/settings/switches', ['weekly_digest_enabled' => true])->assertOk()->assertJson(['weekly_digest_enabled' => true]);

        $this->artisan('digest:weekly')->assertSuccessful();
        Mail::assertSent(WeeklyDigestMail::class, 2);
        Mail::assertSent(WeeklyDigestMail::class, fn ($m) => $m->hasTo('p@digest.test') && isset($m->digest['overdue_deadlines'], $m->digest['renewals']));
        Mail::assertNotSent(WeeklyDigestMail::class, fn ($m) => $m->hasTo('m@digest.test') || $m->hasTo('x@digest.test'));
    }
}
