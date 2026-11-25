<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\MatterCost;
use App\Models\PatentInvoiceIn;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Matter costs (official fees, associates), time cost rates, profitability and leakage. */
class MatterCostsAndProfitabilityTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Project $case;

    private User $finance;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finance = $this->user('finance', 'f@mc.test');
        $this->manager = $this->user('manager', 'm@mc.test');
        $this->client = Client::create(['client_code' => '501M', 'client_type' => 'organization', 'legal_name' => 'Acme Labs', 'company_name' => 'Acme Labs',
            'nationality' => 'India', 'status' => 'Active']);
        $this->case = Project::create(['project_code' => '501M001INPRV', 'docket_number' => '501M001INPRV', 'client_id' => $this->client->id,
            'project_type' => 'Patent', 'project_name' => 'Widget', 'patent_office_code' => 'IN', 'service_code' => 'PRV', 'status' => 'Open',
            'assigned_manager_id' => $this->user('manager', 'other@mc.test')->id]);
        // ₹50,000 professional fees + ₹9,000 IGST, no official fee billed.
        PatentInvoiceIn::create(['type' => 'invoice', 'status' => 'Sent', 'source' => 'manual', 'client_id' => $this->client->id, 'project_id' => $this->case->id,
            'invoice_uin' => '501M001INPRV/1', 'service_fees' => 50000, 'igst_amount' => 9000, 'invoice_amount' => 59000, 'total_bcy' => 59000, 'balance_due' => 0,
            'exchange_rate' => 1, 'payment_status' => 'Confirmed', 'invoice_date' => now()->subDays(60)->toDateString(), 'currency' => 'INR']);
    }

    private function user(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $email, 'password' => bcrypt('password'), 'role' => $role, 'status' => 'Active']);
    }

    private function cost(array $over = []): array
    {
        return array_merge(['project_id' => $this->case->id, 'kind' => 'official_fee', 'vendor_name' => 'Indian Patent Office', 'vendor_reference' => 'CBR 1234',
            'bill_date' => now()->subDays(45)->toDateString(), 'currency' => 'INR', 'amount' => 4000, 'recoverable' => true], $over);
    }

    public function test_costs_feed_profitability_and_flag_official_fees_not_billed(): void
    {
        Sanctum::actingAs($this->finance);
        $this->postJson('/api/finance/costs', $this->cost())->assertCreated()->assertJsonPath('amount_bcy', 4000);
        $this->postJson('/api/finance/costs', $this->cost(['kind' => 'associate', 'vendor_name' => 'Smith LLP', 'currency' => 'USD', 'amount' => 100]))->assertStatus(422);   // no rate
        $this->postJson('/api/finance/costs', $this->cost(['kind' => 'associate', 'vendor_name' => 'Smith LLP', 'currency' => 'USD', 'amount' => 100, 'exchange_rate' => 100,
            'recoverable' => false]))->assertCreated()->assertJsonPath('amount_bcy', 10000);
        TimeEntry::create(['user_id' => $this->manager->id, 'project_id' => $this->case->id, 'duration_hours' => 10, 'entry_date' => now()->subDays(50)->toDateString(),
            'billable' => true, 'status' => 'Submitted']);
        $this->putJson('/api/finance/time-cost-rates', ['default' => 1000])->assertOk();

        $row = $this->getJson('/api/finance/profitability')->assertOk()->json('rows.0');
        $this->assertEquals(50000, $row['billed']);          // without GST
        $this->assertEquals(4000, $row['official_paid']);
        $this->assertEquals(10000, $row['associate']);
        $this->assertEquals(10000, $row['time_cost']);       // 10 h × ₹1,000
        $this->assertEquals(26000, $row['margin']);
        $this->assertEquals(52.0, $row['margin_pct']);
        $this->assertEquals(4000, $row['leakage']);           // paid ₹4,000 official fee, billed none
        $this->assertEquals(4000, $row['unbilled_costs_30d']);

        $byClient = $this->getJson('/api/finance/profitability?by=client')->assertOk()->json('rows.0');
        $this->assertSame('501M · Acme Labs', $byClient['group']);
        $this->assertEquals(26000, $byClient['margin']);

        // The case detail shows its P&L.
        $this->assertEquals(26000, $this->getJson("/api/projects/{$this->case->id}/detail")->assertOk()->json('profitability.margin'));
        // The Claude connector has it too.
        $this->assertEquals(26000, app(\App\Ai\ToolRegistry::class)->call($this->finance, 'matter_profitability', [], 'test')['rows'][0]['margin']);
    }

    public function test_only_finance_records_costs_and_only_accounts_sets_rates(): void
    {
        Sanctum::actingAs($this->manager);
        $this->postJson('/api/finance/costs', $this->cost())->assertForbidden();
        $this->getJson('/api/finance/time-cost-rates')->assertForbidden();
        $this->assertSame([], $this->getJson('/api/finance/costs')->assertOk()->json('data'));   // not their case
        $this->assertSame([], $this->getJson('/api/finance/profitability')->assertOk()->json('rows'));
    }

    public function test_a_closed_month_refuses_costs_dated_in_it(): void
    {
        Sanctum::actingAs($this->finance);
        $month = now()->subMonths(2);
        $this->postJson('/api/finance/controls/lock/'.$month->format('Y-m'))->assertOk();
        $this->postJson('/api/finance/costs', $this->cost(['bill_date' => $month->copy()->startOfMonth()->addDays(3)->toDateString()]))->assertStatus(423);
        $this->assertSame(0, MatterCost::count());
    }
}
