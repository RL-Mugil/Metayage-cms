<?php

namespace Tests\Feature;

use App\Models\AdvanceQuoteLink;
use App\Models\Client;
use App\Models\ClientLedger;
use App\Models\PatentInvoiceIn;
use App\Models\User;
use App\Services\ClientLedgerSync;
use App\Services\QuoteFirst;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Quote-first billing: quotes are the receivable; a tax invoice follows payment unless a reason is given or the client is exempt. */
class QuoteFirstBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $finance;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->user('super_admin', 'admin@qf.test');
        $this->finance = $this->user('finance', 'acc@qf.test');
        $this->client = Client::create(['client_code' => '701M', 'client_type' => 'organization', 'legal_name' => 'Gamma Labs', 'company_name' => 'Gamma Labs',
            'nationality' => 'India', 'status' => 'Active']);
    }

    private function user(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $email, 'password' => bcrypt('password'), 'role' => $role, 'status' => 'Active']);
    }

    /** ₹10,000 professional fees + 18% GST = ₹11,800. */
    private function quote(): PatentInvoiceIn
    {
        return PatentInvoiceIn::create(['type' => 'quote', 'status' => 'Sent', 'source' => 'manual', 'client_id' => $this->client->id,
            'docket_number' => '701M001INPAT', 'invoice_uin' => '701M001INPAT/Q1', 'invoice_date' => now()->subDays(5)->toDateString(),
            'service_fees' => 10000, 'patent_office_fees' => 0, 'other_expenses' => 0, 'igst_amount' => 1800, 'cgst_amount' => 0, 'sgst_amount' => 0,
            'invoice_amount' => 11800, 'total_bcy' => 11800, 'exchange_rate' => 1, 'currency' => 'INR', 'payment_status' => 'Pending']);
    }

    private function turnOn(array $exempt = []): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/settings/quote-first', ['enabled' => true, 'exempt_client_ids' => $exempt])->assertOk()->assertJsonPath('enabled', true);
    }

    public function test_only_a_system_admin_changes_the_rule_and_it_is_off_by_default(): void
    {
        $this->assertFalse(QuoteFirst::enabled());
        Sanctum::actingAs($this->finance);
        $this->putJson('/api/settings/quote-first', ['enabled' => true])->assertForbidden();
        $this->turnOn();
        $this->assertTrue(QuoteFirst::enabled());
        $this->assertDatabaseHas('audit_logs', ['action' => 'update_quote_first_billing', 'user_id' => $this->admin->id]);
    }

    public function test_off_a_quote_converts_without_any_question(): void
    {
        $quote = $this->quote();
        Sanctum::actingAs($this->finance);
        $this->postJson("/api/patent-invoices/in/{$quote->id}/convert")->assertCreated();
        $this->assertNotNull($quote->fresh()->converted_invoice_id);
        $this->assertSame(0, PatentInvoiceIn::openQuotes()->count());
    }

    public function test_on_an_unpaid_quote_needs_a_reason_which_is_audited(): void
    {
        $quote = $this->quote();
        $this->turnOn();
        Sanctum::actingAs($this->finance);
        $this->postJson("/api/patent-invoices/in/{$quote->id}/convert")->assertStatus(422)->assertJsonPath('code', 'quote_first');
        $this->assertNull($quote->fresh()->converted_invoice_id);

        $this->postJson("/api/patent-invoices/in/{$quote->id}/convert", ['override_reason' => 'Client asked for the tax invoice to release payment'])->assertCreated();
        $this->assertNotNull($quote->fresh()->converted_invoice_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'quote_first_override', 'subject_id' => $quote->id]);
    }

    public function test_an_exempt_client_is_invoiced_before_payment(): void
    {
        $quote = $this->quote();
        $this->turnOn([$this->client->id]);
        Sanctum::actingAs($this->finance);
        $this->postJson("/api/patent-invoices/in/{$quote->id}/convert")->assertCreated();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'quote_first_override']);
    }

    public function test_a_tds_shortfall_up_to_ten_percent_of_fees_counts_as_paid(): void
    {
        $quote = $this->quote();
        $this->turnOn();
        // Paid ₹10,800: ₹1,000 short = 10% TDS on ₹10,000 fees.
        AdvanceQuoteLink::create(['zoho_payment_id' => '9001', 'payment_number' => 'PAY-1', 'client_id' => $this->client->id,
            'patent_invoice_in_id' => $quote->id, 'amount' => 10800, 'created_by_id' => $this->finance->id]);
        $this->assertTrue(QuoteFirst::paidState($quote)['fully_paid']);

        Sanctum::actingAs($this->finance);
        $this->postJson("/api/patent-invoices/in/{$quote->id}/convert")->assertCreated();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'quote_first_override']);
    }

    public function test_a_short_payment_beyond_tds_is_not_paid(): void
    {
        $quote = $this->quote();
        AdvanceQuoteLink::create(['zoho_payment_id' => '9002', 'payment_number' => 'PAY-2', 'client_id' => $this->client->id,
            'patent_invoice_in_id' => $quote->id, 'amount' => 9000, 'created_by_id' => $this->finance->id]);
        $state = QuoteFirst::paidState($quote);
        $this->assertFalse($state['fully_paid']);
        $this->assertSame(2800.0, $state['due']);
    }

    public function test_proformas_are_never_ledger_lines_and_are_listed_apart_on_the_statement(): void
    {
        $this->quote();
        $this->turnOn();
        app(ClientLedgerSync::class)->sync($this->client->id);
        $this->assertSame(0, ClientLedger::where('client_id', $this->client->id)->where('document_type', 'Quote')->count());   // never counted
        $this->assertEquals(0, (float) ClientLedger::where('client_id', $this->client->id)->orderByDesc('id')->value('balance'));

        $statement = app(\App\Services\ClientStatement::class);
        $html = fn (string $view) => view('pdf.client-statement', (function () use ($statement, $view) {
            $lines = $statement->lines($this->client->id);
            $quotes = $view === 'plain' ? [] : $statement->openQuotes($this->admin, $this->client->id);
            $summary = $statement->summary($lines);
            $summary['quoted'] = round((float) collect($quotes)->sum('net_due'), 2);

            return ['title' => 'Statement of Account', 'client' => $this->client, 'clientName' => 'Gamma Labs', 'lines' => $lines, 'summary' => $summary,
                'quotes' => $quotes, 'internal' => false, 'withProformas' => $view !== 'plain', 'letterhead' => app(\App\Services\Letterhead::class)->get()];
        })())->render();

        $client = $html('client');
        $this->assertStringContainsString('Proformas (not yet invoiced', $client);
        $this->assertStringContainsString('701M001INPAT/Q1', $client);
        $this->assertStringNotContainsString('Quote', $client);
        $this->assertStringNotContainsString('701M001INPAT/Q1', $html('plain'));

        // Both exports download for staff; "plain" has no proforma section.
        Sanctum::actingAs($this->finance);
        $this->get("/api/clients/{$this->client->id}/statement?format=pdf&view=plain")->assertOk();
        $this->get("/api/clients/{$this->client->id}/statement?format=xlsx&view=internal")->assertOk();
        $this->get("/api/clients/{$this->client->id}/detail")->assertOk()->assertJsonPath('quotes_open.0.number', '701M001INPAT/Q1');
    }

    public function test_a_new_invoice_before_payment_needs_a_reason_too(): void
    {
        $this->turnOn();
        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);
        QuoteFirst::check($this->client->id, null, null, $this->finance, 'new invoice');
    }

    public function test_ledger_credit_and_proformas_give_the_total_due(): void
    {
        $net = fn (float $balance, float $quoted) => \App\Services\ClientStatement::withProformas(['balance' => $balance, 'quoted' => $quoted])['total_receivable'];
        $this->assertEquals(61300, $net(-3600, 64900));    // credit with us is deducted from the proformas
        $this->assertEquals(70000, $net(5100, 64900));     // a debit balance is added
        $this->assertEquals(-2000, $net(-66900, 64900));   // still in credit: balance credit

        $this->quote();
        $statement = app(\App\Services\ClientStatement::class);
        $lines = $statement->lines($this->client->id);
        $quotes = $statement->openQuotes($this->admin, $this->client->id);
        $summary = $statement->summary($lines);
        $summary['quoted'] = round((float) collect($quotes)->sum('net_due'), 2);
        $summary = \App\Services\ClientStatement::withProformas($summary);
        $this->assertEquals(11800, $summary['total_receivable']);
        $html = view('pdf.client-statement', ['title' => 'Statement of Account', 'client' => $this->client, 'clientName' => 'Gamma Labs', 'lines' => $lines,
            'summary' => $summary, 'quotes' => $quotes, 'internal' => true, 'withProformas' => true, 'letterhead' => app(\App\Services\Letterhead::class)->get()])->render();
        $this->assertStringContainsString('Total due', $html);
        $this->assertStringContainsString('11,800.00', $html);
        $this->assertStringContainsString('Add: proformas due', $html);
    }
}
