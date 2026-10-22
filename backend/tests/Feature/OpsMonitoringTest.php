<?php

namespace Tests\Feature;

use App\Mail\OpsReportMail;
use App\Models\OpsIncident;
use App\Models\User;
use App\Services\OpsAlertService;
use App\Services\OpsDailyReport;
use App\Services\OpsIncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class OpsMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ops_alerts.email' => 'ops@example.com']);
        Cache::flush();
    }

    /** The report's live checks look at Redis; stand in for it so these tests do not need a Redis server. */
    private function redisUp(): void
    {
        Redis::shouldReceive('connection')->andReturn(new class { public function ping(): bool { return true; } });
    }

    private function zohoTimeout(string $page = '1'): ConnectionException
    {
        return new ConnectionException("cURL error 28: Operation timed out after 30001 milliseconds with 473856 bytes received for https://www.zohoapis.in/books/v3/invoices?page={$page}&per_page=200");
    }

    // ---- health endpoint -------------------------------------------------------------------------------------------

    public function test_health_endpoint_is_public_and_reports_ok_without_leaking_details(): void
    {
        $this->redisUp();
        $this->getJson('/api/health')->assertOk()->assertExactJson(['status' => 'ok', 'checks' => ['database' => true, 'redis' => true]]);
    }

    public function test_health_endpoint_returns_503_when_a_dependency_is_down(): void
    {
        Redis::shouldReceive('connection')->andThrow(new \RuntimeException('redis down'));
        $this->getJson('/api/health')->assertStatus(503)->assertExactJson(['status' => 'degraded', 'checks' => ['database' => true, 'redis' => false]]);
    }

    // ---- collecting problems quietly ---------------------------------------------------------------------------------

    public function test_reporting_a_problem_sends_no_email_and_repeats_are_counted_not_repeated(): void
    {
        Mail::fake();

        app(OpsAlertService::class)->alertException($this->zohoTimeout('1'));
        app(OpsAlertService::class)->alertException($this->zohoTimeout('2'));
        app(OpsAlertService::class)->alertException($this->zohoTimeout('3'));

        Mail::assertNothingSent();
        $this->assertSame(1, OpsIncident::count(), 'one problem, however many times it repeats');
        $incident = OpsIncident::first();
        $this->assertSame(3, $incident->occurrences);
        $this->assertStringContainsString('Zoho Books', $incident->title);
        $this->assertNotSame('', $incident->meaning);
        $this->assertNotSame('', $incident->impact);
        $this->assertNotSame('', $incident->action);
    }

    public function test_database_errors_get_a_plain_english_explanation(): void
    {
        $e = new \Illuminate\Database\QueryException('pgsql', 'insert into x', [], new \PDOException('SQLSTATE[22001]: String data, right truncated'));
        $e->errorInfo = ['22001', 7, 'value too long for type character varying(255)'];

        app(OpsAlertService::class)->alertException($e);

        $incident = OpsIncident::firstOrFail();
        $this->assertSame('A database request failed', $incident->title);
        $this->assertStringContainsString('longer than the database allows', $incident->meaning);
    }

    public function test_the_scheduler_failure_line_is_not_reported_twice_for_the_same_command(): void
    {
        $inc = app(OpsIncidentService::class);
        $inc->record('app|x', 'Boom', 'm', 'i', 'a', 't', 360, 'zoho:poll');

        $inc->recordException(new \Exception("Scheduled command ['/usr/bin/php8.4' 'artisan' zoho:poll] failed with exit code [1]."));

        $this->assertSame(1, OpsIncident::count(), 'the wrapper repeats an error that was just recorded');
    }

    // ---- the one morning report ----------------------------------------------------------------------------------------

    public function test_when_everything_is_fine_the_morning_report_says_all_healthy(): void
    {
        $this->redisUp();
        Mail::fake();
        Cache::put('ops:scheduler:beat', now()->timestamp, 600);
        DB::table('integration_logs')->insert(['slug' => 'zoho', 'event_type' => 'poll', 'status' => 'ok', 'summary' => 'ok', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertTrue(app(OpsDailyReport::class)->sendIfDue());

        Mail::assertSent(OpsReportMail::class, function (OpsReportMail $m) {
            return $m->hasTo('ops@example.com') && str_contains($m->subjectLine, 'all healthy') && str_contains($m->htmlBody, 'All healthy');
        });
    }

    public function test_the_report_lists_each_open_problem_in_plain_english_and_only_one_goes_out_per_day(): void
    {
        $this->redisUp();
        Mail::fake();
        Cache::put('ops:scheduler:beat', now()->timestamp, 600);
        app(OpsAlertService::class)->alertException($this->zohoTimeout());

        $this->assertTrue(app(OpsDailyReport::class)->sendIfDue());
        $this->assertFalse(app(OpsDailyReport::class)->sendIfDue(), 'a second report the same day is not sent');
        $this->assertTrue(app(OpsDailyReport::class)->sendIfDue(true), '--force overrides');

        Mail::assertSent(OpsReportMail::class, 2);
        Mail::assertSent(OpsReportMail::class, function (OpsReportMail $m) {
            return str_contains($m->subjectLine, 'need') && str_contains($m->htmlBody, 'Zoho Books was slow or unreachable')
                && str_contains($m->htmlBody, 'What happened') && str_contains($m->htmlBody, 'What it affects') && str_contains($m->htmlBody, 'What to do');
        });
        $this->assertNotNull(OpsIncident::first()->reported_at);
    }

    // ---- recovery -----------------------------------------------------------------------------------------------------

    public function test_a_reported_problem_that_recovers_triggers_one_immediate_recovery_email(): void
    {
        $this->redisUp();
        Mail::fake();
        app(OpsAlertService::class)->alertException($this->zohoTimeout());
        app(OpsDailyReport::class)->sendIfDue();           // now it has been reported
        Mail::assertSent(OpsReportMail::class, 1);

        OpsIncident::query()->update(['last_seen_at' => now()->subHours(2)]);   // quiet for longer than its quiet period
        app(OpsIncidentService::class)->sweep();

        Mail::assertSent(OpsReportMail::class, fn (OpsReportMail $m) => $m->kind === 'recovery' && str_contains($m->subjectLine, 'recovered'));
        Mail::assertSent(OpsReportMail::class, 2);
        $this->assertSame('resolved', OpsIncident::first()->status);

        app(OpsIncidentService::class)->sweep();           // nothing more to say
        Mail::assertSent(OpsReportMail::class, 2);
    }

    public function test_a_problem_that_comes_and_goes_between_reports_sends_no_recovery_email(): void
    {
        $this->redisUp();
        Mail::fake();
        app(OpsAlertService::class)->alertException($this->zohoTimeout());
        OpsIncident::query()->update(['last_seen_at' => now()->subHours(2)]);

        app(OpsIncidentService::class)->sweep();

        Mail::assertNothingSent();
        $report = app(OpsDailyReport::class)->build();
        $this->assertStringContainsString('Happened and fixed itself', $report['html']);
    }

    public function test_a_scheduled_command_succeeding_resolves_its_problem_and_sends_a_recovery_notice(): void
    {
        Mail::fake();
        $inc = app(OpsIncidentService::class);
        $inc->record('app|poll', 'Zoho poll failed', 'm', 'i', 'a', 't', 360, 'zoho:poll');
        OpsIncident::query()->update(['reported_at' => now()]);

        $inc->commandSucceeded('zoho:poll');

        $this->assertSame('resolved', OpsIncident::first()->status);
        Mail::assertSent(OpsReportMail::class, fn (OpsReportMail $m) => $m->kind === 'recovery');
    }

    public function test_a_problem_that_comes_back_after_recovering_starts_a_fresh_episode(): void
    {
        $inc = app(OpsIncidentService::class);
        $inc->record('app|a', 'A', 'm', 'i', 'a', 't');
        $inc->resolve('app|a');
        $inc->record('app|a', 'A', 'm', 'i', 'a', 't');

        $row = OpsIncident::first();
        $this->assertSame('open', $row->status);
        $this->assertSame(1, $row->occurrences);
        $this->assertNull($row->reported_at);
    }

    // ---- housekeeping ------------------------------------------------------------------------------------------------

    public function test_prune_removes_old_operational_rows_and_never_touches_audit_or_business_data(): void
    {
        $user = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => bcrypt('secret-pass-1'), 'role' => 'super_admin', 'status' => 'Active']);
        $old = now()->subDays(10);
        $recent = now()->subDays(2);

        DB::table('ip_notifications')->insert([
            ['user_id' => $user->id, 'type' => 'x', 'title' => 'old read', 'description' => 'd', 'read_at' => $old, 'created_at' => $old, 'updated_at' => $old],
            ['user_id' => $user->id, 'type' => 'x', 'title' => 'old unread', 'description' => 'd', 'read_at' => null, 'created_at' => $old, 'updated_at' => $old],
            ['user_id' => $user->id, 'type' => 'x', 'title' => 'recent read', 'description' => 'd', 'read_at' => $recent, 'created_at' => $recent, 'updated_at' => $recent],
        ]);
        DB::table('audit_logs')->insert(['user_id' => $user->id, 'action' => 'create', 'subject_type' => 'Client', 'subject_id' => 1, 'created_at' => $old]);

        $this->artisan('ops:prune-operational', ['--days' => 7, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(3, DB::table('ip_notifications')->count(), 'dry run must not delete');

        $this->artisan('ops:prune-operational', ['--days' => 7])->assertSuccessful();

        $this->assertEqualsCanonicalizing(['old unread', 'recent read'], DB::table('ip_notifications')->pluck('title')->all());
        $this->assertSame(1, DB::table('audit_logs')->count(), 'audit logs are never pruned');
    }
}
