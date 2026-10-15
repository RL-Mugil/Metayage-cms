<?php

namespace Tests\Feature;

use App\Exceptions\ZohoBudgetExhausted;
use App\Models\{Integration, User, ZohoInvoice, ZohoSnapshot};
use App\Services\{ZohoApiBudget, ZohoBooksService, ZohoMirrorSync, ZohoRecordLifecycle};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Http, Storage};
use Tests\TestCase;

class ZohoContinuationDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Kolkata')->setDate(2026, 10, 8)->setTime(3, 0));
        Storage::fake('local');
        Http::preventStrayRequests();
        Integration::create(['slug' => 'zoho', 'name' => 'Zoho Books', 'description' => 'Accounting', 'category' => 'Finance',
            'initials' => 'ZB', 'color' => 'red', 'connected' => true,
            'config' => ['client_id' => 'test', 'client_secret' => encrypt('secret'), 'refresh_token' => encrypt('refresh'), 'organization_id' => '123', 'region' => 'in']]);
        Cache::put('zoho_access_token:in:123', 'fake-token', 86400);
        config(['zoho.webhook_secret' => 'testsecret123456789']);
        $this->actingAs(User::factory()->create(['role' => 'finance', 'status' => 'Active']));
    }

    private function eligible(int $calls = 1850): void
    {
        DB::table('zoho_api_budgets')->insert(['scope' => 'in:123', 'budget_date' => now('Asia/Kolkata')->toDateString(),
            'calls' => $calls, 'automatic_calls' => 1800, 'manual_read_calls' => 30, 'write_calls' => 20]);
        DB::table('zoho_sync_progress')->insert(['scope' => 'in:123', 'resource' => '__cycle', 'started_at' => now(),
            'pause_reason' => 'automatic_daily', 'evening_eligible_date' => now('Asia/Kolkata')->toDateString()]);
    }

    private function record(): ZohoInvoice
    {
        $record = ZohoInvoice::create(['zoho_id' => '900', 'zoho_type' => 'invoice', 'number' => 'INV-900', 'total' => 100]);
        ZohoSnapshot::create(['scope' => 'in:123', 'resource' => 'invoices', 'remote_id' => '900', 'summary' => '{}',
            'detail' => '{"invoice_id":"900"}', 'fingerprint' => str_repeat('a', 64), 'detail_fingerprint' => str_repeat('a', 64),
            'detail_synced_at' => now()->subDays(8), 'pdf_path' => 'zoho/test.pdf.encrypted']);
        Storage::disk('local')->put('zoho/test.pdf.encrypted', encrypt('%PDF-1.4 fixture'));
        return $record;
    }

    private function notify(array $overrides = [], bool $valid = true)
    {
        $payload = json_encode(array_replace(['organization_id' => '123', 'resource' => 'invoices', 'event' => 'deleted', 'remote_id' => '900'], $overrides));
        $signature = base64_encode(hash_hmac('sha256', $payload, $valid ? config('zoho.webhook_secret') : 'wrongsecret', true));
        return $this->call('POST', '/api/webhooks/zoho/deletions', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_ZOHO_WEBHOOK_SIGNATURE' => $signature,
        ], $payload);
    }

    public function test_evening_spends_only_unused_allowance_and_next_day_starts_fresh(): void
    {
        $this->eligible();
        $this->travelTo(now('Asia/Kolkata')->setTime(23, 0));
        $date = now('Asia/Kolkata')->toDateString();
        $budget = app(ZohoApiBudget::class);
        for ($i = 0; $i < 150; $i++) {
            $budget->reserve('in:123', true, false, $date);
        }
        try {
            $budget->reserve('in:123', true, false, $date);
            $this->fail('Evening must stop at the combined daily maximum.');
        } catch (ZohoBudgetExhausted) {
            $this->assertSame(2000, $budget->status('in:123')['used']);
            $this->assertSame(150, $budget->status('in:123')['evening_used']);
        }
        $this->travelTo(now('Asia/Kolkata')->addDay()->startOfDay());
        try {
            $budget->reserve('in:123', true, false, $date);
            $this->fail('Yesterday\'s continuation must never charge today.');
        } catch (ZohoBudgetExhausted $error) {
            $this->assertSame('evening_window', $error->reason);
        }
        $this->travelTo(now('Asia/Kolkata')->setTime(3, 0));
        $budget->reserve('in:123', true);
        $this->assertSame(1, $budget->status('in:123')['used']);
        $this->assertSame(0, $budget->status('in:123')['evening_used']);
    }

    public function test_evening_rejects_before_window_and_near_midnight_without_network(): void
    {
        $this->eligible();
        foreach ([[22, 59, 59], [23, 58, 50]] as [$hour, $minute, $second]) {
            $this->travelTo(now('Asia/Kolkata')->setTime($hour, $minute, $second));
            $this->assertSame('paused', app(ZohoMirrorSync::class)->run(null, true)['status']);
        }
        Http::assertNothingSent();
    }

    public function test_no_evening_run_for_completed_cycle_or_non_budget_failure(): void
    {
        $this->eligible();
        $this->travelTo(now('Asia/Kolkata')->setTime(23, 0));
        DB::table('zoho_sync_progress')->update(['completed_at' => now()]);
        $this->assertSame('paused', app(ZohoMirrorSync::class)->run(null, true)['status']);
        DB::table('zoho_sync_progress')->update(['completed_at' => null, 'evening_eligible_date' => null, 'pause_reason' => 'error']);
        $this->assertSame('paused', app(ZohoMirrorSync::class)->run(null, true)['status']);
        Http::assertNothingSent();
    }

    public function test_morning_checkpoint_resumes_in_evening_without_relisting_page_one(): void
    {
        DB::table('zoho_api_budgets')->insert(['scope' => 'in:123', 'budget_date' => now('Asia/Kolkata')->toDateString(), 'calls' => ZohoApiBudget::NIGHTLY_LIMIT - 1, 'automatic_calls' => ZohoApiBudget::NIGHTLY_LIMIT - 1]);
        Http::fake(fn ($r) => Http::response(['invoices' => [['invoice_id' => '900', 'invoice_number' => 'INV-900']], 'page_context' => ['has_more_page' => true]]));
        $this->assertSame('paused', app(ZohoMirrorSync::class)->run(null, false, true)['status']);
        $this->assertDatabaseHas('zoho_sync_progress', ['resource' => 'invoices', 'next_page' => 2]);
        $this->assertDatabaseHas('zoho_sync_progress', ['resource' => '__cycle', 'evening_eligible_date' => '2026-10-08']);
        $this->travelTo(now('Asia/Kolkata')->setTime(23, 0));
        DB::table('zoho_api_budgets')->update(['calls' => 1998, 'manual_read_calls' => 100, 'write_calls' => 98]);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(fn ($r) => str_contains($r->url(), '/invoices/900?')
            ? Http::response(['invoice' => ['invoice_id' => '900', 'invoice_number' => 'INV-900']])
            : Http::response(['invoices' => [['invoice_id' => '901', 'invoice_number' => 'INV-901']], 'page_context' => ['has_more_page' => true]]));
        $this->assertSame('paused', app(ZohoMirrorSync::class)->run(null, true)['status']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => (int) ($r['page'] ?? 0) === 2);
        $this->assertDatabaseHas('zoho_sync_progress', ['resource' => 'invoices', 'next_page' => 3]);
    }

    public function test_manual_exhaustion_cannot_mark_cycle_as_morning_eligible(): void
    {
        DB::table('zoho_api_budgets')->insert(['scope' => 'in:123', 'budget_date' => now('Asia/Kolkata')->toDateString(), 'calls' => 1800, 'automatic_calls' => 1800]);
        $this->assertSame('paused', app(ZohoMirrorSync::class)->run()['status']);
        $this->assertNull(DB::table('zoho_sync_progress')->where('resource', '__cycle')->value('evening_eligible_date'));
    }

    public function test_evening_yields_to_manual_waiter_and_rechecks_cutoff_after_oauth(): void
    {
        $this->eligible();
        $this->travelTo(now('Asia/Kolkata')->setTime(23, 0));
        Cache::put('zoho_manual_waiting:in:123', true, 5);
        $this->assertSame('paused', app(ZohoMirrorSync::class)->run(null, true)['status']);
        Http::assertNothingSent();
        Cache::forget('zoho_manual_waiting:in:123');
        Cache::forget('zoho_access_token:in:123');
        $this->travelTo(now('Asia/Kolkata')->setTime(23, 58, 40));
        Http::fake(function () {
            $this->travel(20)->seconds();
            return Http::response(['access_token' => 'fake-token']);
        });
        $this->assertSame('paused', app(ZohoMirrorSync::class)->run(null, true)['status']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/books/'));
    }

    public function test_authenticated_duplicate_deletions_hide_mirror_purge_pdf_and_use_no_api_calls(): void
    {
        $record = $this->record();
        $this->notify()->assertStatus(202);
        $this->notify()->assertStatus(202);
        $this->assertDatabaseCount('zoho_webhook_events', 1);
        $this->artisan('zoho:process-deletions')->assertExitCode(0);
        $this->assertNull(ZohoInvoice::find($record->id));
        $this->assertTrue(ZohoInvoice::withTrashed()->find($record->id)->trashed());
        $this->assertSame(0, app(ZohoMirrorSync::class)->pending()->count());
        $this->getJson('/api/integrations/zoho/records/' . $record->id . '/pdf')->assertNotFound();
        Storage::disk('local')->assertMissing('zoho/test.pdf.encrypted');
        $this->notify()->assertStatus(202);
        $this->artisan('zoho:process-deletions')->assertExitCode(0);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'zoho_remote_deleted')->count());
        Http::assertNothingSent();
    }

    public function test_forged_wrong_org_and_unconfigured_webhooks_are_rejected(): void
    {
        $this->notify([], false)->assertUnauthorized();
        $this->notify(['organization_id' => '999'])->assertForbidden();
        config(['zoho.webhook_secret' => null]);
        $this->notify([], false)->assertStatus(503);
        $this->assertDatabaseCount('zoho_webhook_events', 0);
    }

    public function test_stale_inflight_detail_cannot_resurrect_a_deleted_record(): void
    {
        $record = $this->record();
        Http::fake(function () {
            app(ZohoRecordLifecycle::class)->delete('in:123', 'invoices', '900', 'webhook');
            return Http::response(['invoice' => ['invoice_id' => '900', 'invoice_number' => 'INV-900', 'total' => 200]]);
        });
        $this->assertSame('ok', app(ZohoMirrorSync::class)->run($record)['status']);
        $this->assertNull(ZohoInvoice::find($record->id));
        $this->assertSame(0, ZohoSnapshot::count());
        Http::assertSentCount(1);
    }

    public function test_definitive_missing_record_is_deleted_but_generic_404_is_not(): void
    {
        $record = $this->record();
        Http::fake(['*' => Http::response([], 404)]);
        $this->assertSame('fail', app(ZohoMirrorSync::class)->run($record)['status']);
        $this->assertNotNull($record->fresh());
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['code' => 1002, 'message' => 'Invoice does not exist.'], 400)]);
        $this->assertSame('ok', app(ZohoMirrorSync::class)->run($record)['status']);
        $this->assertNull(ZohoInvoice::find($record->id));
        Http::assertSentCount(1);
    }

    public function test_rolling_saved_id_check_recovers_missed_deletion_before_any_list_request(): void
    {
        $record = $this->record();
        DB::table('zoho_api_budgets')->insert(['scope' => 'in:123', 'budget_date' => now('Asia/Kolkata')->toDateString(), 'calls' => ZohoApiBudget::NIGHTLY_LIMIT - 1, 'automatic_calls' => ZohoApiBudget::NIGHTLY_LIMIT - 1]);
        Http::fake(['*' => Http::response(['code' => 1002, 'message' => 'Invoice does not exist.'], 404)]);
        $this->assertSame('paused', app(ZohoMirrorSync::class)->run(null, false, true)['status']);
        $this->assertNull(ZohoInvoice::find($record->id));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/invoices/900?'));
    }

    public function test_same_remote_id_in_another_organization_is_not_deleted_or_exposed(): void
    {
        $this->record();
        ZohoInvoice::create(['scope' => 'in:999', 'zoho_id' => '900', 'zoho_type' => 'invoice', 'number' => 'OTHER-ORG']);
        $this->assertSame(1, ZohoInvoice::count());
        app(ZohoRecordLifecycle::class)->delete('in:123', 'invoices', '900', 'webhook');
        $this->assertSame(0, ZohoInvoice::count());
        $this->assertSame(1, ZohoInvoice::withoutGlobalScopes()->where('scope', 'in:999')->whereNull('deleted_at')->count());
    }

    public function test_different_ids_with_same_number_are_flagged_not_merged(): void
    {
        ZohoInvoice::create(['zoho_id' => '1', 'zoho_type' => 'invoice', 'number' => 'DUPLICATE']);
        ZohoInvoice::create(['zoho_id' => '2', 'zoho_type' => 'invoice', 'number' => 'DUPLICATE']);
        $this->getJson('/api/integrations/zoho/all')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.duplicate_number', true);
        $this->postJson('/api/integrations/zoho/match', ['uins' => ['DUPLICATE']])->assertOk()->assertExactJson([]);
        Http::assertNothingSent();
    }

    public function test_failed_deletion_events_are_visible_and_can_be_replayed(): void
    {
        $this->record();
        $this->notify()->assertStatus(202);
        DB::table('zoho_webhook_events')->update(['attempts' => 5]);
        $this->getJson('/api/integrations/zoho/sync-status')->assertOk()->assertJsonPath('deletions.failed', 1);
        $this->artisan('zoho:process-deletions --retry-failed')->assertExitCode(0);
        $this->assertSame(0, ZohoInvoice::count());
        Http::assertNothingSent();
    }

    public function test_daily_checks_include_legacy_rows_and_skip_records_checked_today(): void
    {
        $this->record();
        ZohoSnapshot::query()->update(['detail_synced_at' => now()->subDay()]);
        ZohoInvoice::create(['zoho_id' => '901', 'zoho_type' => 'quote', 'number' => 'LEGACY']);
        $checks = app(\App\Services\ZohoDeletionChecks::class);
        $this->assertSame(2, $checks->status('in:123')['due']);
        ZohoSnapshot::query()->update(['detail_synced_at' => now()]);
        $this->assertSame(1, $checks->status('in:123')['due']);
        $this->getJson('/api/integrations/zoho/sync-status')->assertOk()
            ->assertJsonPath('deletion_checks.mode', 'api_checks')->assertJsonPath('deletion_checks.checked_today', 1);
        Http::assertNothingSent();
    }

    public function test_legacy_cycle_schema_is_repaired_without_resetting_checkpoints(): void
    {
        DB::table('zoho_sync_progress')->insert(['scope' => 'in:123', 'resource' => 'invoices', 'next_page' => 8]);
        DB::table('zoho_sync_progress')->insert(['scope' => 'in:123', 'resource' => '__cycle']);
        \Illuminate\Support\Facades\Schema::table('zoho_sync_progress', fn ($table) => $table->dropColumn('started_at'));
        $migration = require database_path('migrations/2026_10_07_000006_repair_zoho_cycle_started_at.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('zoho_sync_progress', ['scope' => 'in:123', 'resource' => 'invoices', 'next_page' => 8]);
        $this->assertNotNull(DB::table('zoho_sync_progress')->where('resource', '__cycle')->value('started_at'));
    }

    public function test_priority_checks_leave_room_for_lists_then_spare_capacity_checks_the_rest_once(): void
    {
        for ($i = 1; $i <= 301; $i++) {
            $json = json_encode(['invoice_id' => (string) $i, 'invoice_number' => 'INV-' . $i]);
            $hash = hash('sha256', $json);
            ZohoInvoice::create(['zoho_id' => (string) $i, 'zoho_type' => 'invoice', 'number' => 'INV-' . $i]);
            ZohoSnapshot::create(['scope' => 'in:123', 'resource' => 'invoices', 'remote_id' => (string) $i,
                'summary' => $json, 'detail' => $json, 'fingerprint' => $hash, 'detail_fingerprint' => $hash,
                'pdf_fingerprint' => $hash, 'detail_synced_at' => now()->subDay()]);
        }
        $requests = [];
        Http::fake(function ($r) use (&$requests) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            $requests[] = $path;
            $parts = explode('/', trim($path, '/'));
            $resource = $parts[2];
            if (isset($parts[3])) {
                return Http::response(['invoice' => ['invoice_id' => $parts[3], 'invoice_number' => 'INV-' . $parts[3]]]);
            }
            return Http::response([$resource => [], 'page_context' => ['has_more_page' => false]]);
        });
        $this->assertSame('ok', app(ZohoMirrorSync::class)->run(null, false, true)['status']);
        $this->assertSame('/books/v3/invoices', $requests[300]);
        $this->assertSame('/books/v3/invoices/301', $requests[305]);
        Http::assertSentCount(306);
        $this->assertSame(0, app(\App\Services\ZohoDeletionChecks::class)->status('in:123')['due']);
        $this->travel(1)->hours();
        $this->assertSame('ok', app(ZohoMirrorSync::class)->run()['status']);
        Http::assertSentCount(306);
    }

    public function test_legacy_quote_deletion_needs_one_detail_call_and_no_webhook(): void
    {
        config(['zoho.webhook_secret' => null]);
        $quote = ZohoInvoice::create(['zoho_id' => '901', 'zoho_type' => 'quote', 'number' => 'LEGACY']);
        DB::table('zoho_api_budgets')->insert(['scope' => 'in:123', 'budget_date' => now('Asia/Kolkata')->toDateString(), 'calls' => ZohoApiBudget::NIGHTLY_LIMIT - 1, 'automatic_calls' => ZohoApiBudget::NIGHTLY_LIMIT - 1]);
        Http::fake(['*' => Http::response(['code' => 1002, 'message' => 'Estimate does not exist.'], 400)]);
        $this->assertSame('paused', app(ZohoMirrorSync::class)->run(null, false, true)['status']);
        $this->assertNull(ZohoInvoice::find($quote->id));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/estimates/901?'));
    }

    public function test_books_resource_missing_variant_removes_a_known_detail_record(): void
    {
        $record = $this->record();
        Http::fake(['*' => Http::response(['code' => 1002, 'message' => 'Resource does not exist.'], 404)]);
        $this->assertSame('ok', app(ZohoMirrorSync::class)->run($record)['status']);
        $this->assertNull(ZohoInvoice::find($record->id));
        Http::assertSentCount(1);
    }

    public function test_missing_pdf_never_proves_the_invoice_was_deleted(): void
    {
        $record = $this->record();
        Http::fake(['*' => Http::response(['code' => 1002, 'message' => 'Resource does not exist.'], 404)]);
        try {
            app(ZohoBooksService::class)->pdf('invoices', '900');
            $this->fail('Missing PDF must fail without deleting the invoice.');
        } catch (\App\Exceptions\ZohoApiException $error) {
            $this->assertSame(404, $error->status);
        }
        $this->assertNotNull(ZohoInvoice::find($record->id));
        Http::assertSentCount(1);
    }

    public function test_books_quote_terminology_is_supported_for_estimate_deletions(): void
    {
        $record = ZohoInvoice::create(['zoho_id' => '901', 'zoho_type' => 'quote', 'number' => 'OLD-QUOTE']);
        Http::fake(['*' => Http::response(['code' => 1002, 'message' => 'Quote does not exist.'], 404)]);
        $this->assertSame('ok', app(ZohoMirrorSync::class)->run($record)['status']);
        $this->assertNull(ZohoInvoice::find($record->id));
        Http::assertSentCount(1);
    }

    public function test_api_check_checkpoint_resumes_next_unchecked_id_after_budget_pause(): void
    {
        $this->record();
        ZohoSnapshot::query()->update(['detail_synced_at' => now()->subDay()]);
        ZohoInvoice::create(['zoho_id' => '901', 'zoho_type' => 'invoice', 'number' => 'LEGACY']);
        DB::table('zoho_api_budgets')->insert(['scope' => 'in:123', 'budget_date' => now('Asia/Kolkata')->toDateString(), 'calls' => ZohoApiBudget::NIGHTLY_LIMIT - 1, 'automatic_calls' => ZohoApiBudget::NIGHTLY_LIMIT - 1]);
        Http::fake(fn ($r) => Http::response(['invoice' => ['invoice_id' => '901', 'invoice_number' => 'LEGACY', 'total' => 100]]));
        $this->assertSame('paused', app(ZohoMirrorSync::class)->run(null, false, true)['status']);
        Http::assertSentCount(1);
        $this->assertSame(1, app(\App\Services\ZohoDeletionChecks::class)->status('in:123')['due']);
        $this->travelTo(now('Asia/Kolkata')->setTime(23, 0));
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['code' => 1002, 'message' => 'Invoice does not exist.'], 400)]);
        app(ZohoMirrorSync::class)->run(null, true);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/invoices/900?'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/invoices/901?'));
    }
}
