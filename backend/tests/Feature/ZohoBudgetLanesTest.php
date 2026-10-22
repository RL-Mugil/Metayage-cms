<?php

namespace Tests\Feature;

use App\Models\{Integration, User, ZohoInvoice, ZohoSnapshot};
use App\Services\{ZohoApiBudget, ZohoDeletionChecks, ZohoMirrorSync};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Http, Storage};
use Tests\TestCase;

class ZohoBudgetLanesTest extends TestCase
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
        $this->actingAs(User::factory()->create(['role' => 'finance', 'status' => 'Active']));
    }

    public function test_the_five_minute_poll_is_charged_to_its_own_pool_not_the_nightly_one(): void
    {
        Http::fake(fn () => Http::response(['invoices' => [], 'estimates' => [], 'page_context' => ['has_more_page' => false]]));

        $this->artisan('zoho:poll')->assertSuccessful();

        $row = DB::table('zoho_api_budgets')->first();
        $this->assertSame(1, (int) $row->poll_calls, 'the poll has its own 350-call pool');
        $this->assertSame(0, (int) $row->automatic_calls, 'so the nightly sync cannot starve it');
    }

    public function test_the_poll_keeps_running_after_the_nightly_pool_is_used_up(): void
    {
        DB::table('zoho_api_budgets')->insert(['scope' => 'in:123', 'budget_date' => now('Asia/Kolkata')->toDateString(), 'calls' => ZohoApiBudget::NIGHTLY_LIMIT, 'automatic_calls' => ZohoApiBudget::NIGHTLY_LIMIT]);
        Http::fake(fn () => Http::response(['invoices' => [], 'estimates' => [], 'page_context' => ['has_more_page' => false]]));

        $this->artisan('zoho:poll')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertDatabaseHas('integration_logs', ['event_type' => 'poll', 'status' => 'ok']);
    }

    public function test_the_spare_deletion_pass_leaves_headroom_in_the_nightly_pool(): void
    {
        for ($i = 1; $i <= 301; $i++) {
            $json = json_encode(['invoice_id' => (string) $i, 'invoice_number' => 'INV-'.$i]);
            $hash = hash('sha256', $json);
            ZohoInvoice::create(['zoho_id' => (string) $i, 'zoho_type' => 'invoice', 'number' => 'INV-'.$i]);
            ZohoSnapshot::create(['scope' => 'in:123', 'resource' => 'invoices', 'remote_id' => (string) $i, 'summary' => $json, 'detail' => $json,
                'fingerprint' => $hash, 'detail_fingerprint' => $hash, 'pdf_fingerprint' => $hash, 'detail_synced_at' => now()->subDay()]);
        }
        // After the 300 priority checks and the five lists the pool is within 250 calls of its limit, so the spare pass must stop.
        DB::table('zoho_api_budgets')->insert(['scope' => 'in:123', 'budget_date' => now('Asia/Kolkata')->toDateString(), 'calls' => 900, 'automatic_calls' => 900]);
        Http::fake(function ($r) {
            $parts = explode('/', trim(parse_url($r->url(), PHP_URL_PATH), '/'));
            if (isset($parts[3])) {
                return Http::response(['invoice' => ['invoice_id' => $parts[3], 'invoice_number' => 'INV-'.$parts[3]]]);
            }

            return Http::response([$parts[2] => [], 'page_context' => ['has_more_page' => false]]);
        });

        $this->assertSame('ok', app(ZohoMirrorSync::class)->run(null, false, true)['status']);

        Http::assertSentCount(305);                                  // 300 priority checks + 5 lists, no spare checks
        $this->assertSame(1, app(ZohoDeletionChecks::class)->status('in:123')['due'], 'the last record waits for tomorrow');
    }
}
