<?php

namespace Tests\Feature;

use App\Exceptions\ZohoBudgetExhausted;
use App\Models\Integration;
use App\Models\User;
use App\Models\ZohoInvoice;
use App\Models\ZohoSnapshot;
use App\Services\ZohoApiBudget;
use App\Services\ZohoBooksService;
use App\Services\ZohoMirrorSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ZohoSyncBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 8)->setTime(4, 0));
        Storage::fake('local');
        Http::preventStrayRequests();
        Integration::create(['slug' => 'zoho', 'name' => 'Zoho Books', 'description' => 'Accounting', 'category' => 'Finance',
            'initials' => 'ZB', 'color' => 'red', 'connected' => true,
            'config' => ['client_id' => 'test', 'client_secret' => encrypt('secret'),
                'refresh_token' => encrypt('refresh'), 'organization_id' => '123', 'region' => 'in']]);
        Cache::put('zoho_access_token:in:123', 'fake-token', 3300);
    }

    private function budget(int $calls): void
    {
        DB::table('zoho_api_budgets')->updateOrInsert(['scope' => 'in:123'], [
            'budget_date' => now('Asia/Kolkata')->toDateString(), 'calls' => $calls, 'automatic_calls' => $calls,
        ]);
    }

    private function fakeRemote(bool $twoPages = false): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($twoPages) {
            if (str_contains($request->url(), '/oauth/')) {
                return Http::response(['access_token' => 'fake-token']);
            }
            $parts = explode('/', trim(parse_url($request->url(), PHP_URL_PATH), '/'));
            $resource = $parts[2];
            $id = $parts[3] ?? null;
            if (($request['accept'] ?? null) === 'pdf') {
                return Http::response('%PDF-1.4 fixture', 200, ['Content-Type' => 'application/pdf']);
            }
            [$idKey, $key] = ZohoBooksService::RESOURCES[$resource];
            if ($id) {
                return Http::response([$key => [$idKey => $id, ($resource === 'estimates' ? 'estimate_number' : 'invoice_number') => 'TEST-' . $id,
                    'status' => 'paid', 'balance' => 0, 'total' => 100,
                    'line_items' => [['description' => 'Patent drafting', 'rate' => 100]]]]);
            }
            $page = (int) ($request['page'] ?? 1);
            return Http::response([$resource => $resource === 'invoices' ? [[
                'invoice_id' => (string) $page, 'invoice_number' => 'TEST-' . $page,
                'status' => 'paid', 'balance' => 0, 'total' => 100,
            ]] : [], 'page_context' => ['has_more_page' => $twoPages && $resource === 'invoices' && $page === 1]]);
        });
    }

    public function test_background_stops_at_1800_but_manual_can_use_reserve(): void
    {
        $this->budget(1800);
        $budget = app(ZohoApiBudget::class);
        try {
            $budget->reserve('in:123', true);
            $this->fail('Background must not consume reserved calls.');
        } catch (ZohoBudgetExhausted) {
            $budget->reserve('in:123', false);
            $this->assertSame(1801, $budget->status('in:123')['used']);
        }
    }

    public function test_2000_limit_blocks_network_even_after_cache_clear(): void
    {
        $this->budget(2000);
        Cache::flush();
        Http::fake();
        try {
            app(ZohoBooksService::class)->detail('invoices', '1');
            $this->fail('Budget must reject the call.');
        } catch (ZohoBudgetExhausted) {
            Http::assertNothingSent();
        }
    }

    public function test_budget_resets_at_ist_midnight_and_is_shared_by_service_instances(): void
    {
        $this->budget(2000);
        $this->travelTo(now('Asia/Kolkata')->addDay()->startOfDay());
        app(ZohoApiBudget::class)->reserve('in:123', false);
        $this->assertSame(1, (new ZohoApiBudget)->status('in:123')['used']);
    }

    public function test_failed_network_call_still_consumes_budget(): void
    {
        Http::fake(['*' => Http::response([], 500)]);
        try {
            app(ZohoBooksService::class)->detail('invoices', '1');
        } catch (\RuntimeException) {
            $this->assertSame(1, app(ZohoApiBudget::class)->status('in:123')['used']);
            return;
        }
        $this->fail('HTTP failure should throw.');
    }

    public function test_page_checkpoint_resumes_next_day_without_restarting(): void
    {
        $this->budget(ZohoApiBudget::NIGHTLY_LIMIT - 1);
        $this->fakeRemote(true);
        $this->assertSame('paused', app(ZohoMirrorSync::class)->run()['status']);
        $this->assertDatabaseHas('zoho_sync_progress', ['resource' => 'invoices', 'next_page' => 2]);
        $this->assertDatabaseHas('zoho_invoices', ['zoho_id' => '1']);
        $this->travel(1)->days();
        $this->fakeRemote(true);
        $this->assertTrue(app(ZohoMirrorSync::class)->run()['ok']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/invoices?') && (int) $r['page'] === 1);
        $this->assertDatabaseCount('zoho_invoices', 2);
        $this->assertSame(0, app(ZohoMirrorSync::class)->pending()->count());
    }

    public function test_detail_and_pdf_are_encrypted_and_repeated_run_uses_no_calls(): void
    {
        $this->fakeRemote();
        $this->assertTrue(app(ZohoMirrorSync::class)->run()['ok']);
        $snapshot = ZohoSnapshot::firstOrFail();
        $this->assertStringContainsString('Patent drafting', $snapshot->detail);
        $this->assertStringNotContainsString('Patent drafting', $snapshot->getRawOriginal('detail'));
        $this->assertSame('%PDF-1.4 fixture', decrypt(Storage::disk('local')->get($snapshot->pdf_path)));
        $used = app(ZohoApiBudget::class)->status('in:123')['used'];
        $this->assertTrue(app(ZohoMirrorSync::class)->run()['ok']);
        $this->assertSame($used, app(ZohoApiBudget::class)->status('in:123')['used']);
    }

    public function test_selective_refresh_calls_only_one_document_and_keeps_manual_link(): void
    {
        $this->fakeRemote();
        Sanctum::actingAs(User::factory()->create(['role' => 'finance', 'status' => 'Active']));
        $record = ZohoInvoice::create(['zoho_type' => 'invoice', 'zoho_id' => '99', 'number' => 'OLD', 'match_source' => 'manual']);
        $this->postJson("/api/integrations/zoho/records/{$record->id}/sync")->assertOk()->assertJson(['ok' => true]);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($r) => ! str_contains($r->url(), '/invoices/99?'));
        $this->assertSame('manual', $record->fresh()->match_source);
        $this->assertSame('paid', $record->fresh()->status);
        $this->getJson("/api/integrations/zoho/records/{$record->id}/pdf")->assertNotFound();
        Http::assertSentCount(1);
    }

    public function test_unauthorized_refresh_and_pdf_never_call_zoho(): void
    {
        $this->fakeRemote();
        Sanctum::actingAs(User::factory()->create(['role' => 'associate', 'status' => 'Active']));
        $record = ZohoInvoice::create(['zoho_type' => 'invoice', 'zoho_id' => '99', 'number' => 'TEST']);
        $this->postJson("/api/integrations/zoho/records/{$record->id}/sync")->assertForbidden();
        $this->getJson("/api/integrations/zoho/records/{$record->id}/pdf")->assertForbidden();
        $this->getJson('/api/integrations/zoho/sync-status')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_manual_budget_failure_is_not_reported_as_success(): void
    {
        $this->budget(2000);
        $this->fakeRemote();
        Sanctum::actingAs(User::factory()->create(['role' => 'finance', 'status' => 'Active']));
        $record = ZohoInvoice::create(['zoho_type' => 'invoice', 'zoho_id' => '99', 'number' => 'TEST']);
        $this->postJson("/api/integrations/zoho/records/{$record->id}/sync")->assertStatus(429)->assertJson(['ok' => false]);
        Http::assertNothingSent();
    }

    public function test_overlap_lock_prevents_duplicate_downloads(): void
    {
        $this->fakeRemote();
        $lock = Cache::lock('zoho_sync:in:123', 60);
        $lock->get();
        try {
            $this->assertSame('busy', app(ZohoMirrorSync::class)->run()['status']);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_malformed_list_response_cannot_advance_checkpoint(): void
    {
        Http::fake(['*' => Http::response(['code' => 0])]);
        $this->assertSame('fail', app(ZohoMirrorSync::class)->run()['status']);
        $this->assertDatabaseHas('zoho_sync_progress', ['resource' => 'invoices', 'next_page' => 1, 'completed_at' => null]);
    }

    public function test_status_is_local_and_reports_call_budget(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'finance', 'status' => 'Active']));
        $this->budget(1950);
        $this->getJson('/api/integrations/zoho/sync-status')->assertOk()
            ->assertJsonPath('budget.used', 1950)->assertJsonPath('budget.remaining', 50);
        Http::assertNothingSent();
    }

    public function test_manager_cannot_refresh_an_unassigned_invoice(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'manager', 'status' => 'Active']));
        $record = ZohoInvoice::create(['zoho_type' => 'invoice', 'zoho_id' => '99', 'number' => 'TEST']);
        $this->postJson("/api/integrations/zoho/records/{$record->id}/sync")->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_list_does_not_silently_stop_at_100_pages(): void
    {
        Http::fake(fn ($r) => Http::response(['invoices' => [['invoice_id' => (string) $r['page']]],
            'page_context' => ['has_more_page' => (int) $r['page'] < 101]]));
        $zoho = app(ZohoBooksService::class);
        $zoho->background = true;
        $this->assertCount(101, $zoho->listAllInvoices());
        Http::assertSentCount(101);
    }

    public function test_nightly_schedule_is_3am_ist(): void
    {
        $events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();
        $event = collect($events)->first(fn ($e) => str_contains($e->command ?? '', 'zoho:sync'));
        $this->assertNotNull($event);
        $this->assertSame('0 3 * * *', $event->expression);
        $this->assertSame('Asia/Kolkata', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_each_pool_is_independent_and_cannot_borrow_reserved_calls(): void
    {
        $this->budget(1800);
        DB::table('zoho_api_budgets')->where('scope', 'in:123')->update(['manual_read_calls' => 100, 'calls' => 1900]);
        $budget = app(ZohoApiBudget::class);
        foreach ([true, false] as $background) {
            try {
                $budget->reserve('in:123', $background);
                $this->fail('An exhausted read pool must not borrow write calls.');
            } catch (ZohoBudgetExhausted) {
                $this->assertSame(1900, $budget->status('in:123')['used']);
            }
        }
        $budget->reserve('in:123', false, true);
        $this->assertSame(['used' => 1, 'limit' => 100, 'remaining' => 99], $budget->status('in:123')['pools']['write']);
        DB::table('zoho_api_budgets')->where('scope', 'in:123')->update(['automatic_calls' => 0, 'manual_read_calls' => 0, 'write_calls' => 100, 'calls' => 100]);
        try {
            $budget->reserve('in:123', false, true);
            $this->fail('Writes must stop at 100 even when reads are unused.');
        } catch (ZohoBudgetExhausted) {
            $budget->reserve('in:123', true);
            $budget->reserve('in:123', false);
            $this->assertSame(102, $budget->status('in:123')['used']);
        }
        $this->travel(1)->days();
        $budget->reserve('in:123', false, true);
        $this->assertSame(1, $budget->status('in:123')['used']);
        $this->assertSame(0, $budget->status('in:123')['pools']['automatic']['used']);
        $this->assertSame(0, $budget->status('in:123')['pools']['manual_read']['used']);
    }

    public function test_pdf_backlog_resumes_without_repeating_lists_and_includes_next_day_existence_check(): void
    {
        // Five list calls and one detail call exhaust the nightly allowance (ZohoApiBudget::NIGHTLY_LIMIT).
        $this->budget(ZohoApiBudget::NIGHTLY_LIMIT - 6);
        $this->fakeRemote();
        $this->assertSame('paused', app(ZohoMirrorSync::class)->run()['status']);
        $snapshot = ZohoSnapshot::firstOrFail();
        $this->assertNotNull($snapshot->detail_synced_at);
        $this->assertNull($snapshot->pdf_path);
        $this->assertDatabaseHas('zoho_sync_progress', ['resource' => '__cycle', 'completed_at' => null]);

        $this->travel(1)->days();
        Cache::put('zoho_access_token:in:123', 'fake-token', 3300);
        $this->fakeRemote();
        $this->assertTrue(app(ZohoMirrorSync::class)->run()['ok']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/invoices/1?') && ($r['accept'] ?? null) === 'pdf');
        Http::assertNotSent(fn ($r) => isset($r['page']));
        $this->assertSame(2, app(ZohoApiBudget::class)->status('in:123')['used']);
        $this->assertNotNull($snapshot->fresh()->pdf_path);
    }

    public function test_quote_manual_refresh_uses_exactly_one_books_call(): void
    {
        $this->fakeRemote();
        Sanctum::actingAs(User::factory()->create(['role' => 'finance', 'status' => 'Active']));
        $record = ZohoInvoice::create(['zoho_type' => 'quote', 'zoho_id' => '88', 'number' => 'OLD']);
        $this->postJson("/api/integrations/zoho/records/{$record->id}/sync")->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/estimates/88?') && ! isset($r['accept']));
        $this->assertSame('TEST-88', $record->fresh()->number);
        $this->assertSame(1, app(ZohoApiBudget::class)->status('in:123')['used']);
    }

    public function test_token_renewal_does_not_add_a_second_books_data_call(): void
    {
        Cache::forget('zoho_access_token:in:123');
        $this->fakeRemote();
        Sanctum::actingAs(User::factory()->create(['role' => 'finance', 'status' => 'Active']));
        $record = ZohoInvoice::create(['zoho_type' => 'invoice', 'zoho_id' => '99', 'number' => 'OLD']);
        $this->postJson("/api/integrations/zoho/records/{$record->id}/sync")->assertOk();
        Http::assertSentCount(2); // One Accounts token request plus one Books request.
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), '/books/v3/')));
        $this->assertSame(1, app(ZohoApiBudget::class)->status('in:123')['used']);
    }

    public function test_provider_cooldown_survives_cache_clear_and_returns_retry_after(): void
    {
        Http::fake(['*' => Http::response(['code' => 44], 429, ['Retry-After' => '120'])]);
        Sanctum::actingAs(User::factory()->create(['role' => 'finance', 'status' => 'Active']));
        $record = ZohoInvoice::create(['zoho_type' => 'invoice', 'zoho_id' => '99', 'number' => 'OLD']);
        $url = "/api/integrations/zoho/records/{$record->id}/sync";
        $this->postJson($url)->assertStatus(429)->assertHeader('Retry-After', '120');
        Cache::flush();
        $this->postJson($url)->assertStatus(429)->assertHeader('Retry-After', '120');
        Http::assertSentCount(1);
        $this->assertSame(1, app(ZohoApiBudget::class)->status('in:123')['used']);
        $this->travel(121)->seconds();
        Cache::put('zoho_access_token:in:123', 'fake-token', 3300);
        $this->fakeRemote();
        $this->postJson($url)->assertOk();
        Http::assertSentCount(1);
    }

    public function test_daily_provider_limit_blocks_until_reset_without_retry_storm(): void
    {
        Http::fake(['*' => Http::response(['code' => 45], 429)]);
        $this->assertSame('paused', app(ZohoMirrorSync::class)->run()['status']);
        $this->assertSame('paused', app(ZohoMirrorSync::class)->run()['status']);
        Http::assertSentCount(1);
        $this->assertSame(now('Asia/Kolkata')->addDay()->startOfDay()->timestamp,
            \Carbon\Carbon::parse(DB::table('zoho_api_budgets')->value('blocked_until'))->timestamp);
    }

    public function test_timeout_during_details_stops_after_one_attempt_and_preserves_backlog(): void
    {
        $this->fakeRemote(true);
        $this->assertTrue(app(ZohoMirrorSync::class)->run()['ok']);
        ZohoSnapshot::query()->update(['detail_fingerprint' => null]);
        $attempts = 0;
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new \Illuminate\Http\Client\ConnectionException('timeout');
        });
        $result = app(ZohoMirrorSync::class)->run();
        $this->assertSame('fail', $result['status']);
        $this->assertStringContainsString('timed out', $result['message']);
        $this->assertSame(1, $attempts);
        $this->assertSame(2, app(ZohoMirrorSync::class)->pending()->count());
    }

    public function test_unauthorized_remote_token_is_cleared_without_automatic_retry(): void
    {
        Http::fake(['*' => Http::response(['code' => 57], 401)]);
        $this->assertSame('fail', app(ZohoMirrorSync::class)->run()['status']);
        $this->assertNull(Cache::get('zoho_access_token:in:123'));
        Http::assertSentCount(1);
    }

    public function test_transport_lock_blocks_other_entry_points_without_spending_calls(): void
    {
        Http::fake();
        $lock = Cache::lock('zoho_request:in:123', 90);
        $lock->get();
        try {
            app(ZohoBooksService::class)->pingOrganization();
            $this->fail('A concurrent request must not bypass the transport lock.');
        } catch (ZohoBudgetExhausted) {
            Http::assertNothingSent();
            $this->assertSame(0, app(ZohoApiBudget::class)->status('in:123')['used']);
        } finally {
            $lock->release();
        }
    }

    public function test_poll_yields_quietly_when_another_request_holds_the_lock(): void
    {
        Http::fake();
        $lock = Cache::lock('zoho_request:in:123', 90);
        $lock->get();
        try {
            $this->artisan('zoho:poll')->assertExitCode(0);
            Http::assertNothingSent();
            $this->assertSame(0, DB::table('integration_logs')->where('event_type', 'poll')->where('status', 'fail')->count());
        } finally {
            $lock->release();
        }
    }

    public function test_busy_lock_is_reported_with_the_busy_reason(): void
    {
        Http::fake();
        $lock = Cache::lock('zoho_request:in:123', 90);
        $lock->get();
        try {
            app(ZohoBooksService::class)->pingOrganization();
            $this->fail('Expected the busy lock to stop the request.');
        } catch (ZohoBudgetExhausted $error) {
            $this->assertSame(ZohoBudgetExhausted::BUSY, $error->reason);
        } finally {
            $lock->release();
        }
    }

    public function test_spacing_timestamp_preserves_fractional_seconds(): void
    {
        $this->travelTo(now()->setMicrosecond(123456));
        app(ZohoApiBudget::class)->reserve('in:123', false);
        $this->assertStringEndsWith('.123456', DB::table('zoho_api_budgets')->value('last_call_at'));
    }

    public function test_manual_throttle_blocks_eleventh_refresh_without_spending_a_call(): void
    {
        $this->fakeRemote();
        Sanctum::actingAs(User::factory()->create(['role' => 'finance', 'status' => 'Active']));
        $record = ZohoInvoice::create(['zoho_type' => 'invoice', 'zoho_id' => '99', 'number' => 'OLD']);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/integrations/zoho/records/{$record->id}/sync")->assertOk();
        }
        $this->postJson("/api/integrations/zoho/records/{$record->id}/sync")->assertStatus(429);
        $this->getJson('/api/integrations/zoho/sync-status')->assertOk();
        Http::assertSentCount(10);
    }

    public function test_provider_retry_after_http_date_is_respected(): void
    {
        Http::fake(['*' => Http::response(['code' => 1070], 429,
            ['Retry-After' => now()->addSeconds(90)->toRfc7231String()])]);
        $result = app(ZohoMirrorSync::class)->run();
        $this->assertSame('paused', $result['status']);
        $this->assertSame(90, $result['retry_after']);
        Http::assertSentCount(1);
    }
}
