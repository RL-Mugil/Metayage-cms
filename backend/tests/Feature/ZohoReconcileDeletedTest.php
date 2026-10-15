<?php

namespace Tests\Feature;

use App\Models\{Integration, ZohoInvoice};
use App\Services\ZohoMirrorSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Http};
use Tests\TestCase;

class ZohoReconcileDeletedTest extends TestCase
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

    private function mirror(string $id, string $type = 'invoice'): ZohoInvoice
    {
        $row = ZohoInvoice::create(['scope' => 'in:123', 'zoho_id' => $id, 'zoho_type' => $type, 'number' => "N-{$id}",
            'total' => 100, 'balance' => 100, 'status' => 'overdue']);
        DB::table('zoho_invoices')->where('id', $row->id)->update(['synced_at' => now()->subHour()]);

        return $row;
    }

    /** @param list<string> $invoiceIds ids Zoho still lists; $gone ids answer "does not exist" on a detail read */
    private function fakeZoho(array $invoiceIds, array $gone = []): void
    {
        Http::fake(function ($request) use ($invoiceIds, $gone) {
            $url = $request->url();
            foreach ($gone as $id) {
                if (str_contains($url, "/invoices/{$id}?")) {
                    return Http::response(['code' => 1002, 'message' => 'Invoice does not exist.'], 404);
                }
            }
            if (preg_match('~/invoices/(\d+)\?~', $url, $m)) {
                return Http::response(['invoice' => ['invoice_id' => $m[1], 'invoice_number' => "N-{$m[1]}"]]);
            }
            if (str_contains($url, '/invoices?')) {
                return Http::response(['invoices' => array_map(fn ($id) => ['invoice_id' => $id, 'invoice_number' => "N-{$id}"], $invoiceIds),
                    'page_context' => ['has_more_page' => false]]);
            }
            if (str_contains($url, '/estimates?')) {
                return Http::response(['estimates' => [], 'page_context' => ['has_more_page' => false]]);
            }

            return Http::response([], 500);
        });
    }

    public function test_invoice_deleted_in_zoho_is_removed_and_the_rest_are_kept(): void
    {
        $this->mirror('1');
        $this->mirror('2');
        $this->fakeZoho(['1'], gone: ['2']);

        $result = app(ZohoMirrorSync::class)->reconcileDeleted();

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['removed']);
        $this->assertDatabaseHas('zoho_invoices', ['zoho_id' => '1', 'deleted_at' => null]);
        $this->assertSoftDeleted('zoho_invoices', ['zoho_id' => '2']);
    }

    public function test_a_record_missing_from_the_list_but_still_present_in_zoho_is_kept(): void
    {
        $this->mirror('7');
        $this->fakeZoho([]); // list omits it, but the detail read still returns it

        $result = app(ZohoMirrorSync::class)->reconcileDeleted();

        $this->assertSame(0, $result['removed']);
        $this->assertDatabaseHas('zoho_invoices', ['zoho_id' => '7', 'deleted_at' => null]);
    }

    public function test_an_implausible_number_of_missing_records_removes_nothing(): void
    {
        $ids = range(1, 40);
        foreach ($ids as $id) {
            $this->mirror((string) $id);
        }
        $this->fakeZoho([], gone: array_map('strval', $ids));

        $result = app(ZohoMirrorSync::class)->reconcileDeleted();

        $this->assertSame(0, $result['removed']);
        $this->assertSame(40, ZohoInvoice::count());
    }

    public function test_records_synced_after_the_pass_began_are_never_treated_as_missing(): void
    {
        $row = ZohoInvoice::create(['scope' => 'in:123', 'zoho_id' => '55', 'zoho_type' => 'invoice', 'number' => 'N-55', 'total' => 1, 'balance' => 1, 'status' => 'sent']);
        DB::table('zoho_invoices')->where('id', $row->id)->update(['synced_at' => now()->addMinute()]);
        $this->fakeZoho([], gone: ['55']);

        app(ZohoMirrorSync::class)->reconcileDeleted();

        $this->assertDatabaseHas('zoho_invoices', ['zoho_id' => '55', 'deleted_at' => null]);
    }
}
