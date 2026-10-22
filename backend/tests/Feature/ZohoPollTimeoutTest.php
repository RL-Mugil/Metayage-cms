<?php

namespace Tests\Feature;

use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\{Cache, DB, Http};
use Tests\TestCase;

class ZohoPollTimeoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Integration::create(['slug' => 'zoho', 'name' => 'Zoho Books', 'description' => 'Accounting', 'category' => 'Finance',
            'initials' => 'ZB', 'color' => 'red', 'connected' => true,
            'config' => ['client_id' => 'test', 'client_secret' => encrypt('secret'), 'refresh_token' => encrypt('refresh'), 'organization_id' => '123', 'region' => 'in']]);
        Cache::put('zoho_access_token:in:123', 'fake-token', 86400);
    }

    public function test_a_slow_zoho_is_logged_with_a_short_summary_and_does_not_fail_the_schedule(): void
    {
        $long = 'cURL error 28: Operation timed out after 30001 milliseconds with 473856 bytes received (see https://curl.se/libcurl/c/libcurl-errors.html) for '
            .'https://www.zohoapis.in/books/v3/invoices?page=1&sort_column=last_modified_time&sort_order=D&per_page=200&organization_id=924754718 '.str_repeat('x', 200);
        Http::fake(fn () => throw new ConnectionException($long));

        $this->artisan('zoho:poll')->assertSuccessful();

        $row = DB::table('integration_logs')->where('event_type', 'poll')->latest('id')->first();
        $this->assertSame('fail', $row->status);
        $this->assertLessThanOrEqual(255, mb_strlen($row->summary), 'integration_logs.summary is varchar(255)');
    }

    public function test_the_poll_asks_zoho_for_a_small_page(): void
    {
        Http::fake(fn () => Http::response(['invoices' => [], 'estimates' => [], 'page_context' => ['has_more_page' => false]]));

        $this->artisan('zoho:poll')->assertSuccessful();

        Http::assertSent(fn ($request) => (int) ($request['per_page'] ?? 0) === 25);
    }
}
