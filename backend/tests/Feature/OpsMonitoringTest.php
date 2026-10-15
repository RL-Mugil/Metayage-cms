<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OpsAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class OpsMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $status = 'Active', ?string $email = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $email ?? $role.'@test.local', 'password' => bcrypt('secret-pass-1'),
            'role' => $role, 'status' => $status,
        ]);
    }

    public function test_health_endpoint_is_public_and_reports_ok_without_leaking_details(): void
    {
        Redis::shouldReceive('connection')->andReturn(new class { public function ping(): bool { return true; } });

        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'checks' => ['database' => true, 'redis' => true]]);
    }

    public function test_health_endpoint_returns_503_when_a_dependency_is_down(): void
    {
        Redis::shouldReceive('connection')->andThrow(new \RuntimeException('redis down'));

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'degraded', 'checks' => ['database' => true, 'redis' => false]]);
    }

    public function test_alert_reaches_only_active_admins_and_directors(): void
    {
        Mail::fake();
        Cache::flush();
        $admin = $this->user('super_admin');
        $director = $this->user('partner');
        $this->user('partner', 'Inactive', 'gone@test.local');
        $this->user('associate');
        $this->user('partner', 'Active', 'loadtest+001@example.invalid');

        app(OpsAlertService::class)->alert('Test alert', 'Something failed', 'k1');

        $this->assertEqualsCanonicalizing([$admin->id, $director->id], DB::table('ip_notifications')->where('type', 'system_alert')->pluck('user_id')->all());
        $this->assertSame(2, DB::table('ip_notifications')->where('title', 'Test alert')->count());
    }

    public function test_alerts_with_the_same_key_are_sent_once_per_window(): void
    {
        Mail::fake();
        Cache::flush();
        $this->user('super_admin');

        $svc = app(OpsAlertService::class);
        $svc->alert('First', 'body', 'same-key');
        $svc->alert('Second', 'body', 'same-key');
        $svc->alert('Third', 'body', 'other-key');

        $this->assertSame(1, DB::table('ip_notifications')->where('title', 'First')->count());
        $this->assertSame(0, DB::table('ip_notifications')->where('title', 'Second')->count());
        $this->assertSame(1, DB::table('ip_notifications')->where('title', 'Third')->count());
    }

    public function test_prune_removes_old_operational_rows_and_never_touches_audit_or_business_data(): void
    {
        $user = $this->user('super_admin');
        $old = now()->subDays(10);
        $recent = now()->subDays(2);

        DB::table('ip_notifications')->insert([
            ['user_id' => $user->id, 'type' => 'x', 'title' => 'old read', 'description' => 'd', 'read_at' => $old, 'created_at' => $old, 'updated_at' => $old],
            ['user_id' => $user->id, 'type' => 'x', 'title' => 'old unread', 'description' => 'd', 'read_at' => null, 'created_at' => $old, 'updated_at' => $old],
            ['user_id' => $user->id, 'type' => 'x', 'title' => 'recent read', 'description' => 'd', 'read_at' => $recent, 'created_at' => $recent, 'updated_at' => $recent],
        ]);
        DB::table('audit_logs')->insert([
            'user_id' => $user->id, 'action' => 'create', 'subject_type' => 'Client', 'subject_id' => 1,
            'created_at' => $old,
        ]);

        $this->artisan('ops:prune-operational', ['--days' => 7, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(3, DB::table('ip_notifications')->count(), 'dry run must not delete');

        $this->artisan('ops:prune-operational', ['--days' => 7])->assertSuccessful();

        $titles = DB::table('ip_notifications')->pluck('title')->all();
        $this->assertEqualsCanonicalizing(['old unread', 'recent read'], $titles);
        $this->assertSame(1, DB::table('audit_logs')->count(), 'audit logs are never pruned');
    }
}
