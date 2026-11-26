<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PatentInvoiceIn;
use App\Models\RegisterPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A month of the register as TallyPrime vouchers: balanced, named after the CA's ledgers. */
class TallyExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_month_exports_as_balanced_tally_vouchers(): void
    {
        $client = Client::create(['client_code' => '701M', 'client_type' => 'organization', 'legal_name' => 'Omega Instruments Pvt Ltd', 'company_name' => 'Omega',
            'nationality' => 'India', 'status' => 'Active']);
        $invoice = PatentInvoiceIn::create(['type' => 'invoice', 'status' => 'Sent', 'source' => 'manual', 'client_id' => $client->id, 'invoice_uin' => '701M001INPRV/1',
            'service_fees' => 50000, 'patent_office_fees' => 1600, 'igst_amount' => 9000, 'invoice_amount' => 60600, 'total_bcy' => 60600, 'balance_due' => 0,
            'exchange_rate' => 1, 'payment_status' => 'Confirmed', 'invoice_date' => '2026-10-05', 'currency' => 'INR', 'docket_number' => '701M001INPRV']);
        RegisterPayment::create(['patent_invoice_in_id' => $invoice->id, 'client_id' => $client->id, 'zoho_payment_id' => 'p1', 'payment_number' => 'P501',
            'payment_date' => '2026-10-20', 'amount' => 55600, 'currency' => 'INR', 'exchange_rate' => 1, 'amount_bcy' => 55600, 'payment_mode' => 'Bank Transfer', 'estimated' => false, 'kind' => 'cash']);
        RegisterPayment::create(['patent_invoice_in_id' => $invoice->id, 'client_id' => $client->id, 'zoho_payment_id' => 'tds:p1', 'payment_number' => 'P501',
            'payment_date' => '2026-10-20', 'amount' => 5000, 'currency' => 'INR', 'exchange_rate' => 1, 'amount_bcy' => 5000, 'estimated' => false, 'kind' => 'tds']);

        Sanctum::actingAs(User::create(['name' => 'Manager', 'email' => 'm@tally.test', 'password' => bcrypt('password'), 'role' => 'manager', 'status' => 'Active']));
        $this->get('/api/finance/tally-export?month=2026-10')->assertForbidden();

        Sanctum::actingAs(User::create(['name' => 'Accounts', 'email' => 'f@tally.test', 'password' => bcrypt('password'), 'role' => 'finance', 'status' => 'Active']));
        $this->putJson('/api/finance/tally-ledgers', ['bank' => 'HDFC Bank A/c', 'tds' => 'TDS Receivable FY26-27'])->assertOk();
        $summary = $this->getJson('/api/finance/tally-export?month=2026-10&format=summary')->assertOk()->json();
        $this->assertSame(3, $summary['vouchers']);
        $this->assertFalse($summary['locked']);

        $xml = simplexml_load_string((string) $this->get('/api/finance/tally-export?month=2026-10')->assertOk()->getContent());
        $vouchers = $xml->xpath('//VOUCHER');
        $this->assertCount(3, $vouchers);
        foreach ($vouchers as $v) {
            $sum = array_sum(array_map(fn ($l) => (float) $l->AMOUNT, $v->xpath('ALLLEDGERENTRIES.LIST')));
            $this->assertEqualsWithDelta(0.0, $sum, 0.001, 'Each voucher balances');
        }
        $sales = $xml->xpath("//VOUCHER[@VCHTYPE='Sales']")[0];
        $this->assertSame('701M001INPRV/1', (string) $sales->VOUCHERNUMBER);
        $this->assertSame('20261005', (string) $sales->DATE);
        $party = $sales->xpath("ALLLEDGERENTRIES.LIST[LEDGERNAME='Omega Instruments Pvt Ltd']")[0];
        $this->assertSame('Yes', (string) $party->ISDEEMEDPOSITIVE);
        $this->assertSame('-60600.00', (string) $party->AMOUNT);
        $this->assertNotEmpty($sales->xpath("ALLLEDGERENTRIES.LIST[LEDGERNAME='Patent Office Fees Reimbursement'][AMOUNT='1600.00']"));
        $this->assertNotEmpty($xml->xpath("//VOUCHER[@VCHTYPE='Receipt']/ALLLEDGERENTRIES.LIST[LEDGERNAME='HDFC Bank A/c'][AMOUNT='-55600.00']"));
        $this->assertNotEmpty($xml->xpath("//VOUCHER[@VCHTYPE='Journal']/ALLLEDGERENTRIES.LIST[LEDGERNAME='TDS Receivable FY26-27']"));
        $this->assertDatabaseHas('audit_logs', ['action' => 'tally_export']);
    }
}
