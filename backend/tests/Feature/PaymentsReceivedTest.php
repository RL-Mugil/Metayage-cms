<?php

namespace Tests\Feature;

use App\Models\AdvanceQuoteLink;
use App\Models\Client;
use App\Models\PatentInvoiceIn;
use App\Models\User;
use App\Models\ZohoInvoice;
use App\Models\ZohoOpeningBalance;
use App\Models\ZohoSnapshot;
use App\Services\ZohoBooksService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Payments Received (one row per payment: invoices settled, TDS, unused advance), advances
 * earmarked for proforma quotes (net due in the due list) and opening balances on 31-03-2026.
 */
class PaymentsReceivedTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finance = User::create(['name' => 'Accounts', 'email' => 'accounts@pr.test', 'password' => bcrypt('password'), 'role' => 'finance', 'status' => 'Active']);
        Sanctum::actingAs($this->finance);
        $this->client = Client::create(['client_code' => '445M', 'client_type' => 'organization', 'legal_name' => 'Sustainable Reference Analytics',
            'company_name' => 'Sustainable Reference Analytics', 'nationality' => 'India', 'status' => 'Active', 'zoho_contact_id' => 'cust-445']);

        foreach ([['inv-6', '445M006INPRV349', 37000], ['inv-7', '445M007INPAS627', 11800]] as [$id, $number, $total]) {
            ZohoInvoice::create(['zoho_id' => $id, 'zoho_type' => 'invoice', 'number' => $number, 'status' => 'paid', 'total' => $total, 'balance' => 0,
                'currency' => 'INR', 'txn_date' => '2026-08-26', 'zoho_contact_id' => 'cust-445']);
        }
        foreach ([['pay-322', 'P322', '2026-03-31', 17000, 0, [['invoice_id' => 'inv-6', 'amount_applied' => 17000]]],
            ['pay-377', 'P377', '2026-08-25', 32400, 3600, [['invoice_id' => 'inv-6', 'amount_applied' => 17000, 'tax_amount_withheld' => 3000],
                ['invoice_id' => 'inv-7', 'amount_applied' => 11800, 'tax_amount_withheld' => 0]]]] as [$id, $number, $date, $amount, $unused, $invoices]) {
            ZohoSnapshot::create(['scope' => app(ZohoBooksService::class)->scope(), 'resource' => 'customerpayments', 'remote_id' => $id, 'summary' => '{}',
                'fingerprint' => $id, 'detail' => json_encode(['payment_id' => $id, 'payment_number' => $number, 'date' => $date, 'amount' => $amount,
                    'unused_amount' => $unused, 'exchange_rate' => 1, 'currency_code' => 'INR', 'customer_id' => 'cust-445', 'customer_name' => 'SRA',
                    'payment_mode' => 'Bank Transfer', 'invoices' => $invoices])]);
        }
    }

    private function quote(string $uin, float $amount, ?int $clientId = null): PatentInvoiceIn
    {
        return PatentInvoiceIn::create(['type' => 'quote', 'status' => 'Sent', 'source' => 'manual', 'client_id' => $clientId ?? $this->client->id,
            'invoice_uin' => $uin, 'invoice_date' => '2026-11-20', 'invoice_amount' => $amount, 'total_bcy' => $amount, 'balance_due' => $amount,
            'exchange_rate' => 1, 'payment_status' => 'Pending', 'currency' => 'INR']);
    }

    public function test_each_payment_shows_what_it_settled_its_tds_and_unused_part(): void
    {
        $res = $this->getJson('/api/payments-received')->assertOk()->json();
        $this->assertSame(2, $res['total']);
        $p377 = collect($res['data'])->firstWhere('number', 'P377');
        $this->assertEquals(32400, $p377['amount']);
        $this->assertSame(['445M006INPRV349', '445M007INPAS627'], array_column($p377['applied'], 'invoice'));
        $this->assertEquals(3000, $p377['tds']);
        $this->assertEquals(3600, $p377['unused']);
        $this->assertSame('445M', $p377['client']['code']);
        $this->assertEquals(49400, $res['totals']['received']);
        $this->assertEquals(3600, $res['totals']['unused']);

        $this->assertSame(1, $this->getJson('/api/payments-received?unused=1')->json('total'));
        $this->assertSame(1, $this->getJson('/api/payments-received?search=445M007')->json('total'));

        // Not for someone without finance access.
        Sanctum::actingAs(User::create(['name' => 'Partner', 'email' => 'p@pr.test', 'password' => bcrypt('password'), 'role' => 'partner', 'status' => 'Active']));
        $this->getJson('/api/payments-received')->assertOk();
    }

    public function test_an_advance_earmarked_for_a_quote_shows_the_net_due(): void
    {
        $quote = $this->quote('445M010INPRV500', 35400);

        $this->postJson('/api/payments-received/pay-377/links', ['quote_id' => $quote->id, 'amount' => 4000])->assertStatus(422);   // more than unused
        $this->postJson('/api/payments-received/pay-322/links', ['quote_id' => $quote->id, 'amount' => 100])->assertStatus(422);    // nothing unused
        $other = Client::create(['client_code' => '446M', 'client_type' => 'organization', 'legal_name' => 'Other', 'company_name' => 'Other', 'nationality' => 'India', 'status' => 'Active']);
        $this->postJson('/api/payments-received/pay-377/links', ['quote_id' => $this->quote('446M001INPRV001', 1000, $other->id)->id, 'amount' => 100])->assertStatus(422);

        $this->postJson('/api/payments-received/pay-377/links', ['quote_id' => $quote->id, 'amount' => 3600])->assertCreated();
        $this->assertTrue(\App\Models\AuditLog::where('action', 'link_advance_to_quote')->exists());

        // The due list nets it off the quote.
        $pending = $this->getJson('/api/pending-payments')->assertOk()->json();
        $row = collect($pending['data'])->firstWhere('invoice_uin', '445M010INPRV500');
        $this->assertEquals(3600, $row['advance_linked']);
        $this->assertEquals(31800, $row['net_due']);
        $this->assertEquals(3600, $pending['advance_on_quotes']);
        $this->assertEquals(35400 + 1000 - 3600, $pending['pending_totals']['INR']);   // both open quotes, less the advance

        // So does the internal ledger's quote list; the payment shows where its advance went.
        $quotes = $this->getJson("/api/clients/{$this->client->id}/detail")->assertOk()->json('quotes_open');
        $this->assertEquals(31800, $quotes[0]['net_due']);
        $p377 = collect($this->getJson('/api/payments-received')->json('data'))->firstWhere('number', 'P377');
        $this->assertSame('445M010INPRV500', $p377['links'][0]['quote']);
        $this->assertEquals(0, $p377['unlinked']);

        $this->deleteJson('/api/advance-links/'.$p377['links'][0]['id'])->assertOk();
        $this->assertSame(0, AdvanceQuoteLink::count());
    }

    public function test_only_accounts_can_earmark_advances(): void
    {
        $quote = $this->quote('445M010INPRV500', 35400);
        Sanctum::actingAs(User::create(['name' => 'Analyst', 'email' => 'a@pr.test', 'password' => bcrypt('password'), 'role' => 'associate', 'status' => 'Active']));
        $this->postJson('/api/payments-received/pay-377/links', ['quote_id' => $quote->id, 'amount' => 100])->assertForbidden();
    }

    public function test_opening_balances_are_dated_on_the_opening_date(): void
    {
        app(\App\Services\ZohoOpeningBalanceMirror::class)->save(app(ZohoBooksService::class)->scope(), [
            'contact_id' => 'cust-445', 'contact_type' => 'customer', 'company_name' => 'Sustainable Reference Analytics',
            'outstanding_receivable_amount' => 5000, 'opening_balances' => [['location_id' => 'loc-1', 'opening_balance_amount' => 5000]],
        ]);

        $this->assertSame('2026-03-31', ZohoOpeningBalance::withoutGlobalScopes()->where('zoho_contact_id', 'cust-445')->firstOrFail()->balance_date->toDateString());
    }
}
