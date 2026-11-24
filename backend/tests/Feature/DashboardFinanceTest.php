<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PatentApplication;
use App\Models\PatentInvoiceIn;
use App\Models\RegisterPayment;
use App\Models\RenewalSchedule;
use App\Models\User;
use App\Services\RenewalOutlook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Dashboard money figures from the portal register, and the renewals outlook. */
class DashboardFinanceTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::create(['name' => 'Partner', 'email' => 'p@dash.test', 'password' => bcrypt('password'), 'role' => 'partner', 'status' => 'Active']));
        $this->client = Client::create(['client_code' => '501M', 'client_type' => 'organization', 'legal_name' => 'Orbit Labs', 'company_name' => 'Orbit Labs', 'nationality' => 'India', 'status' => 'Active']);
    }

    private function invoice(string $uin, float $amount, float $balance, string $date, ?string $due = null): PatentInvoiceIn
    {
        return PatentInvoiceIn::create(['type' => 'invoice', 'status' => 'Sent', 'source' => 'zoho', 'client_id' => $this->client->id,
            'invoice_uin' => $uin, 'invoice_amount' => $amount, 'total_bcy' => $amount, 'balance_due' => $balance, 'exchange_rate' => 1,
            'invoice_date' => $date, 'due_date' => $due, 'payment_status' => $balance <= 0 ? 'Confirmed' : 'Pending', 'currency' => 'INR']);
    }

    public function test_collected_this_month_is_by_payment_date_and_finance_tab_adds_up(): void
    {
        $old = $this->invoice('501M001INPRV', 10000, 0, now()->subMonths(3)->toDateString());
        RegisterPayment::create(['patent_invoice_in_id' => $old->id, 'client_id' => $this->client->id, 'zoho_payment_id' => 'p1',
            'payment_date' => now()->toDateString(), 'amount' => 10000, 'amount_bcy' => 10000]);
        $this->invoice('501M002INFER', 6000, 6000, now()->subDays(50)->toDateString(), now()->subDays(40)->toDateString());
        $this->invoice('501M003INCPT', 4000, 4000, now()->toDateString(), now()->addDays(20)->toDateString());

        $metrics = $this->getJson('/api/dashboard/metrics')->assertOk()->json('metrics');
        $this->assertEquals(10000, $metrics['collected_mtd']);          // paid this month on an old invoice
        $this->assertEquals(10000, $metrics['received_payments']);      // all-time stays separate
        $this->assertSame(1, $metrics['overdue_invoices']);

        $finance = $this->getJson('/api/dashboard/finance')->assertOk()->json();
        $this->assertEquals(20000, $finance['billed']);
        $this->assertEquals(10000, $finance['outstanding']);
        $this->assertEquals(50.0, $finance['rate']);
        $ageing = collect($finance['ageing'])->keyBy('key');
        $this->assertEquals(4000, $ageing['current']['amount']);
        $this->assertEquals(6000, $ageing['31_60']['amount']);
        $this->assertEquals($finance['outstanding'], collect($finance['ageing'])->sum('amount'));
        $this->assertSame('501M003INCPT', $finance['recent'][0]['number']);
        $this->assertSame($this->client->id, $finance['top_clients'][0]['client_id']);
        $this->assertCount(12, $finance['monthly']);
        $this->assertCount(3, $finance['forecast']);
        $this->assertEquals(10000, array_sum(array_column($finance['forecast'], 'invoices')));   // both unpaid balances fall in the window
        $this->assertSame('Invoices paid', $finance['funnel'][3]['stage']);
        $this->assertSame(1, $finance['funnel'][3]['count']);
        $this->assertEquals(20000, collect($finance['breakdown']['services'])->sum('billed'));
        $this->assertArrayHasKey('count', $finance['unbilled']);

        $trends = $this->getJson('/api/dashboard/trends')->assertOk()->json();
        $this->assertCount(6, $trends['months']);
        $this->assertEquals(10000, end($trends['collected']));

        $collected = $this->getJson('/api/dashboard/drill/collected')->assertOk()->json();
        $this->assertCount(1, $collected);
        $this->assertSame('501M001INPRV', $collected[0]['number']);
    }

    public function test_quoted_not_invoiced_skips_invoiced_and_cancelled_quotes(): void
    {
        foreach ([['Q1', 'Sent', 'sent', 5000], ['Q2', 'Accepted', 'invoiced', 7000], ['Q3', 'Cancelled', 'declined', 9000], ['Q4', 'Accepted', 'accepted', 3000]] as [$uin, $status, $zoho, $amount]) {
            PatentInvoiceIn::create(['type' => 'quote', 'status' => $status, 'source' => 'zoho', 'zoho_status' => $zoho, 'client_id' => $this->client->id,
                'invoice_uin' => $uin, 'invoice_amount' => $amount, 'total_bcy' => $amount, 'invoice_date' => now()->toDateString()]);
        }
        $metrics = $this->getJson('/api/dashboard/metrics')->assertOk()->json('metrics');
        $this->assertEquals(8000, $metrics['quoted_open']);
        $this->assertSame(2, $metrics['quoted_count']);
        $this->assertEqualsCanonicalizing(['Q1', 'Q4'], collect($this->getJson('/api/dashboard/drill/quoted')->json())->pluck('number')->all());
    }

    public function test_renewal_outlook_lists_the_next_unpaid_fee_per_application(): void
    {
        $app = PatentApplication::create(['client_id' => $this->client->id, 'application_number' => '202141000001', 'jurisdiction' => 'IN', 'legal_status' => 'Granted']);
        RenewalSchedule::create(['patent_application_id' => $app->id, 'renewal_year' => 4, 'due_date' => now()->subDays(10), 'status' => 'Paid']);
        RenewalSchedule::create(['patent_application_id' => $app->id, 'renewal_year' => 5, 'due_date' => now()->addDays(30), 'status' => 'Unpaid']);
        RenewalSchedule::create(['patent_application_id' => $app->id, 'renewal_year' => 6, 'due_date' => now()->addYear(), 'status' => 'Unpaid']);

        $rows = RenewalOutlook::nextDue(PatentApplication::query()->select('id'));
        $this->assertCount(1, $rows);
        $this->assertSame('Renewal year 5', $rows[0]['label']);
        $this->assertSame(30, $rows[0]['days']);
        $this->assertSame('Orbit Labs', $rows[0]['client']['legal_name']);
    }
}
