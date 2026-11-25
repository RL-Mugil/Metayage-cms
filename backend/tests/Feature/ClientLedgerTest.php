<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientLedger;
use App\Models\PatentInvoiceIn;
use App\Models\RegisterPayment;
use App\Models\User;
use App\Models\ZohoInvoice;
use App\Models\ZohoSnapshot;
use App\Services\ZohoBooksService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The client ledger reads like Zoho's customer statement: TDS withheld is its own type and
 * column (not cash), money not applied to any invoice is an Advance credit (Zoho "unused
 * credit"), credit notes are credits, and proforma quotes appear only in the internal view.
 */
class ClientLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->partner = User::create(['name' => 'Partner', 'email' => 'partner@ledger.test', 'password' => bcrypt('password'), 'role' => 'partner', 'status' => 'Active']);
        Sanctum::actingAs($this->partner);
        $this->client = Client::create(['client_code' => '445M', 'client_type' => 'organization', 'legal_name' => 'Sustainable Reference Analytics',
            'company_name' => 'Sustainable Reference Analytics', 'nationality' => 'India', 'status' => 'Active', 'zoho_contact_id' => 'cust-445']);
    }

    private function invoice(string $id, string $number, float $total, float $balance, string $date): PatentInvoiceIn
    {
        $mirror = ZohoInvoice::create(['zoho_id' => $id, 'zoho_type' => 'invoice', 'number' => $number, 'status' => $balance > 0 ? 'sent' : 'paid',
            'total' => $total, 'balance' => $balance, 'currency' => 'INR', 'txn_date' => $date, 'zoho_contact_id' => 'cust-445']);

        return PatentInvoiceIn::where('zoho_invoice_id', $mirror->id)->firstOrFail();
    }

    private function snapshot(string $resource, string $id, array $detail): ZohoSnapshot
    {
        return ZohoSnapshot::create(['scope' => app(ZohoBooksService::class)->scope(), 'resource' => $resource, 'remote_id' => $id,
            'summary' => '{}', 'detail' => json_encode($detail), 'fingerprint' => sha1($id.json_encode($detail))]);
    }

    private function payment(string $id, string $number, string $date, float $amount, float $unused, array $invoices): ZohoSnapshot
    {
        return $this->snapshot('customerpayments', $id, [
            'payment_id' => $id, 'payment_number' => $number, 'date' => $date, 'amount' => $amount, 'unused_amount' => $unused,
            'currency_code' => 'INR', 'exchange_rate' => 1, 'payment_mode' => 'Bank Transfer', 'customer_id' => 'cust-445',
            'invoices' => $invoices,
        ]);
    }

    /** 445M: P377 paid ₹32,400 + ₹3,000 TDS, applied ₹28,800, leaving ₹3,600 unused (Zoho: Balance Due −3,600). */
    private function scenario(): void
    {
        $this->invoice('inv-6', '445M006INPRV349', 37000, 0, '2026-08-26');
        $this->invoice('inv-7', '445M007INPAS627', 11800, 0, '2026-11-12');
        $this->payment('pay-322', 'P322', '2026-03-31', 17000, 0, [['invoice_id' => 'inv-6', 'amount_applied' => 17000, 'tax_amount_withheld' => 0]]);
        $this->payment('pay-377', 'P377', '2026-08-25', 32400, 3600, [
            ['invoice_id' => 'inv-6', 'amount_applied' => 17000, 'tax_amount_withheld' => 3000],
            ['invoice_id' => 'inv-7', 'amount_applied' => 11800, 'tax_amount_withheld' => 0],
        ]);
    }

    public function test_tds_and_unused_credit_make_the_ledger_match_zoho(): void
    {
        $this->scenario();

        $ledger = ClientLedger::where('client_id', $this->client->id)->get();
        $this->assertSame('-3600.00', app(\App\Services\ClientStatement::class)->lines($this->client->id)->last()->balance);

        // TDS is its own type and column, dated with its payment; no estimated "Payment" for it.
        $tds = $ledger->where('document_type', 'TDS')->values();
        $this->assertCount(1, $tds);
        $this->assertSame('3000.00', $tds[0]->tds);
        $this->assertSame('2026-08-25', $tds[0]->transaction_date->toDateString());
        $this->assertSame(0, RegisterPayment::where('estimated', true)->count());

        // The ₹3,600 not applied to any invoice is an Advance credit.
        $advance = $ledger->where('document_type', 'Advance')->values();
        $this->assertCount(1, $advance);
        $this->assertSame('3600.00', $advance[0]->credit);
        $this->assertSame('P377 · unused credit', $advance[0]->document_reference);

        // Cash collected excludes TDS.
        $this->assertEquals(45800, app(\App\Services\FinanceFigures::class)->payments($this->partner, $this->client->id)->sum('amount_bcy'));

        $detail = $this->getJson("/api/clients/{$this->client->id}/detail")->assertOk()->json();
        $this->assertEquals(-3600, $detail['ledger_summary']['balance']);
        $this->assertEquals(3000, $detail['ledger_summary']['tds']);
        $this->assertEquals(3600, $detail['ledger_summary']['advance']);
        $this->assertEquals(45800, $detail['ledger_summary']['received']);
    }

    public function test_an_advance_applied_later_is_not_counted_twice(): void
    {
        $this->scenario();
        $this->invoice('inv-8', '445M008INFER100', 3600, 0, '2026-12-01');
        ZohoSnapshot::where('remote_id', 'pay-377')->firstOrFail()->update(['detail' => json_encode([
            'payment_id' => 'pay-377', 'payment_number' => 'P377', 'date' => '2026-08-25', 'amount' => 32400, 'unused_amount' => 0,
            'currency_code' => 'INR', 'exchange_rate' => 1, 'customer_id' => 'cust-445',
            'invoices' => [
                ['invoice_id' => 'inv-6', 'amount_applied' => 17000, 'tax_amount_withheld' => 3000],
                ['invoice_id' => 'inv-7', 'amount_applied' => 11800, 'tax_amount_withheld' => 0],
                ['invoice_id' => 'inv-8', 'amount_applied' => 3600, 'tax_amount_withheld' => 0],
            ],
        ])]);

        $ledger = ClientLedger::where('client_id', $this->client->id)->get();
        $this->assertSame(0, $ledger->where('document_type', 'Advance')->count());
        $this->assertSame('0.00', app(\App\Services\ClientStatement::class)->lines($this->client->id)->last()->balance);
    }

    public function test_a_payment_deleted_in_zoho_leaves_the_ledger(): void
    {
        $this->scenario();
        ZohoSnapshot::where('remote_id', 'pay-377')->firstOrFail()->delete();

        $ledger = ClientLedger::where('client_id', $this->client->id)->get();
        $this->assertSame(0, $ledger->where('document_type', 'Advance')->count());
        $this->assertSame(0, $ledger->where('document_type', 'TDS')->count());
        $this->assertFalse(RegisterPayment::where('zoho_payment_id', 'like', '%pay-377')->exists());
    }

    public function test_credit_notes_are_credits_not_estimated_payments(): void
    {
        $this->invoice('inv-9', '445M009INFER200', 5000, 0, '2026-09-01');
        $this->assertSame(1, RegisterPayment::where('estimated', true)->count());   // paid per Zoho, no record yet

        $this->snapshot('creditnotes', 'cn-1', [
            'creditnote_id' => 'cn-1', 'creditnote_number' => 'CN-00001', 'status' => 'open', 'date' => '2026-09-10', 'total' => 6000,
            'balance' => 1000, 'exchange_rate' => 1, 'currency_code' => 'INR', 'customer_id' => 'cust-445',
            'invoices_credited' => [['invoice_id' => 'inv-9', 'amount_credited' => 5000, 'date' => '2026-09-10']],
        ]);

        $this->assertSame(0, RegisterPayment::where('estimated', true)->count());
        $ledger = ClientLedger::where('client_id', $this->client->id)->get();
        $this->assertSame(['5000.00', '1000.00'], $ledger->where('document_type', 'Credit note')->sortBy('credit')->pluck('credit')->reverse()->values()->all());
        $this->assertSame('-1000.00', app(\App\Services\ClientStatement::class)->lines($this->client->id)->last()->balance);
        // Credit notes are not cash collected.
        $this->assertEquals(0, app(\App\Services\FinanceFigures::class)->payments($this->partner, $this->client->id)->sum('amount_bcy'));
    }

    public function test_open_quotes_are_internal_and_never_in_the_balance(): void
    {
        $this->scenario();
        PatentInvoiceIn::create(['type' => 'quote', 'status' => 'Sent', 'source' => 'manual', 'client_id' => $this->client->id, 'invoice_uin' => '445M010INPRV500',
            'invoice_date' => '2026-11-20', 'invoice_amount' => 35400, 'total_bcy' => 35400, 'exchange_rate' => 1, 'payment_status' => 'Pending', 'currency' => 'INR']);

        $detail = $this->getJson("/api/clients/{$this->client->id}/detail")->assertOk()->json();
        $this->assertCount(1, $detail['quotes_open']);
        $this->assertSame('445M010INPRV500', $detail['quotes_open'][0]['number']);
        $this->assertEquals(-3600, $detail['ledger_summary']['balance']);

        // A client portal login sees its ledger, never the quotes.
        $portal = User::create(['name' => 'Client', 'email' => 'accounts@sra.test', 'password' => bcrypt('password'), 'role' => 'client', 'status' => 'Active']);
        $this->client->update(['portal_user_id' => $portal->id]);
        Sanctum::actingAs($portal);
        $own = $this->getJson("/api/clients/{$this->client->id}/detail");
        if ($own->status() === 200) {
            $this->assertSame([], $own->json('quotes_open'));
        } else {
            $own->assertForbidden();
        }
    }

    public function test_the_client_statement_downloads_without_zoho_wording(): void
    {
        $this->scenario();

        $xlsx = $this->get("/api/clients/{$this->client->id}/statement?format=xlsx&view=client")->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $xlsx->headers->get('Content-Type'));
        $pdf = $this->get("/api/clients/{$this->client->id}/statement?format=pdf")->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));

        $statement = app(\App\Services\ClientStatement::class);
        $lines = $statement->lines($this->client->id);
        $html = view('pdf.client-statement', ['title' => 'Statement of Account', 'client' => $this->client, 'clientName' => 'Sustainable Reference Analytics',
            'lines' => $lines, 'summary' => $statement->summary($lines), 'quotes' => [], 'internal' => false,
            'letterhead' => app(\App\Services\Letterhead::class)->get()])->render();
        $this->assertStringNotContainsString('Zoho', $html);
        $this->assertStringContainsString('3,600.00 Cr', $html);
        $this->assertStringContainsString('TDS', $html);
        $this->assertTrue(\App\Models\AuditLog::where('action', 'download_statement')->exists());
    }
}
