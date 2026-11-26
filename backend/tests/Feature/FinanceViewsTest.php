<?php

namespace Tests\Feature;

use App\Models\AdvanceQuoteLink;
use App\Models\Client;
use App\Models\MatterCost;
use App\Models\PatentInvoiceIn;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Pending Payments (invoices / quotes / both), Financial KPIs that follow the filters, tying costs to cases, analytics. */
class FinanceViewsTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private Client $a;

    private Client $b;

    private Project $case;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finance = $this->user('finance', 'acc@fv.test');
        $this->a = $this->client('801M', 'Alpha Optics');
        $this->b = $this->client('802M', 'Beta Motors');
        $this->case = Project::create(['project_code' => '801M003INPAT', 'docket_number' => '801M003INPAT', 'client_id' => $this->a->id,
            'project_type' => 'Patent', 'project_name' => 'Lens', 'patent_office_code' => 'IN', 'service_code' => 'PAT', 'status' => 'Open']);
        // Alpha: one unpaid invoice ₹11,800; one sent quote ₹5,900 (no payment status, as Zoho quotes); one converted quote.
        $this->doc($this->a, 'invoice', '801M001/1', 11800, ['balance_due' => 11800, 'payment_status' => 'Pending', 'due_date' => now()->subDays(10)->toDateString()]);
        $this->doc($this->a, 'quote', '801M002/Q1', 5900, ['payment_status' => null]);
        $converted = $this->doc($this->a, 'quote', '801M002/Q0', 1000, ['payment_status' => 'Pending']);
        $converted->update(['converted_invoice_id' => $this->doc($this->a, 'invoice', '801M002/1', 1000, ['balance_due' => 0, 'payment_status' => 'Confirmed'])->id]);
        // Beta: one unpaid invoice ₹20,000.
        $this->doc($this->b, 'invoice', '802M001/1', 20000, ['balance_due' => 20000, 'payment_status' => 'Pending', 'due_date' => now()->addDays(5)->toDateString()]);
    }

    private function user(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $email, 'password' => bcrypt('password'), 'role' => $role, 'status' => 'Active']);
    }

    private function client(string $code, string $name): Client
    {
        return Client::create(['client_code' => $code, 'client_type' => 'organization', 'legal_name' => $name, 'company_name' => $name, 'nationality' => 'India', 'status' => 'Active']);
    }

    private function doc(Client $c, string $type, string $uin, float $amount, array $over = []): PatentInvoiceIn
    {
        return PatentInvoiceIn::create($over + ['type' => $type, 'status' => 'Sent', 'source' => 'manual', 'client_id' => $c->id, 'invoice_uin' => $uin,
            'service_fees' => $amount, 'invoice_amount' => $amount, 'total_bcy' => $amount, 'exchange_rate' => 1, 'currency' => 'INR',
            'invoice_date' => now()->subDays(20)->toDateString()]);
    }

    public function test_pending_payments_splits_invoices_quotes_and_both(): void
    {
        Sanctum::actingAs($this->finance);
        $quotes = $this->getJson('/api/pending-payments?doc=quotes')->assertOk()->json();
        $this->assertSame(['801M002/Q1'], array_column($quotes['data'], 'invoice_uin'));   // the converted quote is not pending
        $this->assertSame(['801M001/1', '802M001/1'], collect($this->getJson('/api/pending-payments?doc=invoices')->json('data'))->pluck('invoice_uin')->sort()->values()->all());

        $both = $this->getJson('/api/pending-payments')->assertOk()->json();
        $this->assertSame('both', $both['doc']);
        $this->assertSame(['invoices' => 2, 'quotes' => 1], $both['doc_counts']);
        $this->assertEquals(31800, $both['totals_by_doc']['invoices']['INR']);
        $this->assertEquals(5900, $both['totals_by_doc']['quotes']['INR']);
        $this->assertEquals(37700, $both['pending_totals']['INR']);

        // An advance earmarked for the quote is netted on the quotes total.
        AdvanceQuoteLink::create(['zoho_payment_id' => '7001', 'client_id' => $this->a->id, 'amount' => 900,
            'patent_invoice_in_id' => PatentInvoiceIn::where('invoice_uin', '801M002/Q1')->value('id'), 'created_by_id' => $this->finance->id]);
        $this->assertEquals(5000, $this->getJson('/api/pending-payments?doc=quotes')->json('totals_by_doc.quotes.INR'));
    }

    public function test_financial_kpis_follow_the_register_search(): void
    {
        Sanctum::actingAs($this->finance);
        $all = $this->getJson('/api/financial/stats')->assertOk()->json();
        $this->assertFalse($all['filtered']);
        $this->assertSame(1, $all['quotes_unpaid_count']);

        $alpha = $this->getJson('/api/financial/stats?search=801M')->assertOk()->json();
        $this->assertTrue($alpha['filtered']);
        $this->assertEquals(12800, $alpha['total_billed']);      // 11,800 + the 1,000 invoice from the converted quote
        $this->assertEquals(11800, $alpha['total_outstanding']);
        $this->assertSame(1, $alpha['quotes_unpaid_count']);
        $this->assertEquals(5900, $alpha['quotes_unpaid_value']);
        $this->assertCount(12, $alpha['monthly']);

        $beta = $this->getJson('/api/financial/stats?search=beta')->assertOk()->json();
        $this->assertEquals(20000, $beta['total_billed']);
        $this->assertSame(0, $beta['quotes_unpaid_count']);

        // The register list and the KPI drill-down use the same filter.
        $this->assertSame(['802M001/1'], array_column($this->getJson('/api/patent-invoices/in?search=802M')->json('data'), 'invoice_uin'));
        $this->assertSame(['802M001/1'], array_column($this->getJson('/api/financial/kpi-list?kind=billed&search=802M')->json('data'), 'invoice_code'));
    }

    public function test_a_cost_is_tied_to_its_case_with_the_rest_of_the_bill(): void
    {
        $line = fn (string $kind, float $amount) => MatterCost::create(['kind' => $kind, 'vendor_name' => 'Trumarx', 'vendor_reference' => 'BILL-77 801M003INPAT',
            'bill_date' => now()->subDays(3)->toDateString(), 'currency' => 'INR', 'amount' => $amount, 'exchange_rate' => 1, 'amount_bcy' => $amount,
            'recoverable' => true, 'needs_review' => true, 'source' => 'zoho_bill']);
        $fee = $line('official_fee', 7200);
        $assoc = $line('associate', 5000);

        Sanctum::actingAs($this->finance);
        $s = $this->getJson("/api/finance/costs/{$fee->id}/case-suggestions")->assertOk()->json();
        $this->assertSame($this->case->id, $s['suggestions'][0]['id']);
        $this->assertSame([$assoc->id], array_column($s['siblings'], 'id'));

        $this->postJson('/api/finance/costs/assign', ['ids' => [$fee->id, $assoc->id], 'project_id' => $this->case->id])->assertOk()->assertJsonPath('assigned', 2);
        $this->assertSame($this->a->id, $fee->fresh()->client_id);
        $this->assertTrue($fee->fresh()->needs_review);   // tying a case does not confirm the cost
        $this->assertSame(2, \App\Models\AuditLog::where('action', 'matter_cost_assigned')->count());

        Sanctum::actingAs($this->user('associate', 'eng@fv.test'));
        $this->postJson('/api/finance/costs/assign', ['ids' => [$fee->id], 'project_id' => $this->case->id])->assertForbidden();
    }

    public function test_analytics_shows_quotes_receivables_and_ageing(): void
    {
        Sanctum::actingAs($this->finance);
        $a = $this->getJson('/api/financial/analytics')->assertOk()->json();
        $this->assertSame(1, $a['quotes']['unpaid_count']);
        $this->assertEquals(5900, $a['receivables']['quotes']);
        $this->assertCount(5, $a['ageing']);
        $this->assertEquals(5900, array_sum(array_column($a['ageing'], 'quotes')));
    }

    public function test_payment_reports_include_unpaid_quotes(): void
    {
        $data = app(\App\Services\Reports\PaymentReportData::class)->build($this->finance, null, null, null);
        $quote = collect($data['pending'])->firstWhere('type', 'Quote');
        $this->assertSame('801M002/Q1', $quote['invoice']);
        $this->assertEquals(5900, $quote['balance']);
        $this->assertCount(3, $data['pending']);                       // two unpaid invoices and the open quote
        $this->assertSame(1, $data['summary']['quotes']);
        $this->assertEquals(5900, $data['summary']['quotes_amount']);
        $this->assertEquals(31800, $data['summary']['outstanding']);    // invoices only
        $this->assertEquals(37700, $data['summary']['total_due']);
        $this->assertEquals(31800, $data['summary']['billed'] - $data['summary']['received'] - $data['summary']['waived']);
        $alpha = collect($data['by_client'])->firstWhere('client_code', '801M');
        $this->assertEquals(5900, $alpha['quotes_due']);
        $this->assertEquals(17700, $alpha['total_due']);

        // The downloadable files render with the quote in them.
        $xlsx = app(\App\Services\Reports\ExcelReport::class)->render('Payment Collection', $data, app(\App\Services\Letterhead::class)->get(), null, true);
        $this->assertNotEmpty($xlsx);
        $html = view('pdf.payment-report', ['title' => 'Payment Collection', 'data' => $data, 'cut' => [], 'letterhead' => app(\App\Services\Letterhead::class)->get(),
            'signature' => null, 'seal' => '', 'financialSummary' => true])->render();
        $this->assertStringContainsString('801M002/Q1', $html);
        $this->assertStringContainsString('Unpaid quotes', $html);
    }
}
