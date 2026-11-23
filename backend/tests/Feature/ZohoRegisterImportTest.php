<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PatentInvoiceIn;
use App\Models\User;
use App\Models\ZohoInvoice;
use App\Models\ZohoSnapshot;
use App\Services\ZohoBooksService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Zoho Books records land in the portal register, which every dashboard reads. */
class ZohoRegisterImportTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::create(['name' => 'Partner', 'email' => 'partner@zreg.test', 'password' => bcrypt('password'), 'role' => 'partner', 'status' => 'Active']));
        $this->client = Client::create(['client_code' => '940M', 'client_type' => 'organization', 'legal_name' => 'Agnikul Cosmos', 'company_name' => 'Agnikul Cosmos', 'nationality' => 'India', 'status' => 'Active']);
    }

    private function snapshot(string $resource, string $id, array $detail): void
    {
        ZohoSnapshot::create(['scope' => app(ZohoBooksService::class)->scope(), 'resource' => $resource, 'remote_id' => $id,
            'summary' => '{}', 'detail' => json_encode($detail), 'fingerprint' => sha1($id)]);
    }

    private function paidInvoice(): ZohoInvoice
    {
        $this->snapshot('invoices', 'inv-1', [
            'line_items' => [
                ['name' => 'Professional fees', 'item_total' => 10000],
                ['name' => 'Official fee - Form 18', 'item_total' => 4000],
                ['name' => 'Courier charges', 'item_total' => 500],
            ],
            'taxes' => [['tax_name' => 'IGST18', 'tax_amount' => 1800]],
            'exchange_rate' => 1,
        ]);

        return ZohoInvoice::create(['zoho_id' => 'inv-1', 'zoho_type' => 'invoice', 'number' => '940M001INFER612', 'status' => 'paid',
            'total' => 16300, 'balance' => 0, 'currency' => 'INR', 'txn_date' => now()->subDays(30)->toDateString(), 'due_date' => now()->subDays(15)->toDateString()]);
    }

    public function test_a_zoho_invoice_becomes_a_register_row_with_its_fee_split(): void
    {
        $mirror = $this->paidInvoice();

        $row = PatentInvoiceIn::where('zoho_invoice_id', $mirror->id)->firstOrFail();
        $this->assertSame('zoho', $row->source);
        $this->assertSame($this->client->id, $row->client_id);                 // matched by the client code 940M
        $this->assertSame('940M', $row->client_code_prefix);
        $this->assertSame('IN', $row->patent_office_code);
        $this->assertSame('FER', $row->service_code);
        $this->assertSame('10000.00', $row->service_fees);
        $this->assertSame('4000.00', $row->patent_office_fees);
        $this->assertSame('500.00', $row->other_expenses);
        $this->assertSame('1800.00', $row->igst_amount);
        $this->assertSame('16300.00', $row->invoice_amount);
        $this->assertSame('Confirmed', $row->payment_status);
        $this->assertFalse($row->amount_split_pending);
        $this->assertSame($this->client->id, $mirror->fresh()->client_id);     // the Zoho list improves too

        // A later sync updates the same row.
        $mirror->update(['status' => 'overdue', 'balance' => 6300]);
        $this->assertSame(1, PatentInvoiceIn::where('zoho_invoice_id', $mirror->id)->count());
        $this->assertSame('Partially Paid', $row->fresh()->payment_status);

        // Deleted in Zoho: cancelled here.
        $mirror->delete();
        $this->assertSame('Cancelled', $row->fresh()->status);
    }

    public function test_dashboards_count_the_register(): void
    {
        $this->paidInvoice();
        ZohoInvoice::create(['zoho_id' => 'inv-2', 'zoho_type' => 'invoice', 'number' => '940M002INPRV', 'status' => 'overdue',
            'total' => 5000, 'balance' => 5000, 'currency' => 'INR', 'txn_date' => now()->subDays(20)->toDateString(), 'due_date' => now()->subDays(5)->toDateString()]);

        $stats = $this->getJson('/api/financial/stats')->assertOk()->json();
        $this->assertEquals(21300, $stats['total_billed']);
        $this->assertEquals(16300, $stats['total_received']);
        $this->assertEquals(5000, $stats['total_outstanding']);
        $this->assertSame(1, $stats['overdue_count']);
        $this->assertSame(1, $stats['paid_count']);

        // No line items yet: total counted as service fees until the detail arrives.
        $row = PatentInvoiceIn::where('zoho_number', '940M002INPRV')->firstOrFail();
        $this->assertTrue($row->amount_split_pending);
        $this->assertSame('5000.00', $row->service_fees);
    }

    public function test_a_portal_quote_pushed_to_zoho_is_linked_not_duplicated(): void
    {
        $quote = PatentInvoiceIn::create(['type' => 'quote', 'status' => 'Sent', 'client_id' => $this->client->id, 'docket_number' => '940M003INPAS',
            'invoice_uin' => '940M003INPAS', 'invoice_amount' => 9000, 'service_fees' => 9000]);
        $mirror = ZohoInvoice::create(['zoho_id' => 'est-1', 'zoho_type' => 'quote', 'number' => '940M003INPAS', 'status' => 'accepted',
            'total' => 9000, 'currency' => 'INR', 'txn_date' => '2026-10-02']);

        $this->assertSame(1, PatentInvoiceIn::where('type', 'quote')->count());
        $quote->refresh();
        $this->assertSame($mirror->id, $quote->zoho_invoice_id);
        $this->assertSame('portal', $quote->source);
    }

    public function test_zoho_amounts_are_changed_in_zoho_but_internal_fees_in_the_portal(): void
    {
        $row = PatentInvoiceIn::where('zoho_invoice_id', $this->paidInvoice()->id)->firstOrFail();

        $this->putJson("/api/patent-invoices/in/{$row->id}", ['service_fees' => 1])->assertStatus(422);
        $this->putJson("/api/patent-invoices/in/{$row->id}", ['attorney_fees' => 3000, 'remarks' => 'Split with associate'])->assertOk();
        $row->refresh();
        $this->assertSame('10000.00', $row->service_fees);
        $this->assertSame('3000.00', $row->attorney_fees);
        $this->assertSame('13300.00', $row->net_revenue);
        $this->deleteJson("/api/patent-invoices/in/{$row->id}")->assertStatus(422);
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $mirror = ZohoInvoice::withoutEvents(fn () => ZohoInvoice::create(['scope' => app(ZohoBooksService::class)->scope(), 'zoho_id' => 'inv-9', 'zoho_type' => 'invoice', 'number' => '940M009INCPT',
            'status' => 'sent', 'total' => 1000, 'balance' => 1000, 'currency' => 'INR', 'txn_date' => '2026-09-01']));

        $this->artisan('finance:import-zoho', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, PatentInvoiceIn::where('zoho_invoice_id', $mirror->id)->count());

        $this->artisan('finance:import-zoho')->assertSuccessful();
        $this->assertSame(1, PatentInvoiceIn::where('zoho_invoice_id', $mirror->id)->count());
    }
}
