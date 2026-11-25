<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PatentInvoiceIn;
use App\Models\RegisterPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Zoho Books export files (CSV) into the portal register: preview, no duplicates, case links. */
class ZohoExportImportTest extends TestCase
{
    use RefreshDatabase;

    private const INVOICE_HEADER = ['Invoice Date', 'Invoice ID', 'Invoice Number', 'Invoice Status', 'Customer ID', 'Customer Name', 'Due Date',
        'Currency Code', 'Exchange Rate', 'SubTotal', 'Total', 'Balance', 'Last Payment Date', 'Place of Supply', 'Item Name', 'Item Desc',
        'Account', 'Quantity', 'Item Total', 'CGST', 'SGST', 'IGST', 'CF.Application No:', 'CF.Patent Office:', 'CF.Service Fee',
        'CF.Patent Office Fee', 'CF.Stamp Duty', 'Estimate Number'];

    private const PAYMENT_HEADER = ['Payment Number', 'CustomerPayment ID', 'Mode', 'CustomerID', 'Exchange Rate', 'Amount', 'Unused Amount',
        'Reference Number', 'Currency Code', 'Customer Name', 'Date', 'Amount Applied to Invoice', 'Invoice Number'];

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::create(['name' => 'Finance', 'email' => 'fin@zexp.test', 'password' => bcrypt('password'), 'role' => 'finance', 'status' => 'Active']));
        $this->client = Client::create(['client_code' => '940M', 'client_type' => 'organization', 'legal_name' => 'Agnikul Cosmos', 'company_name' => 'Agnikul Cosmos',
            'nationality' => 'India', 'state' => 'Tamil Nadu', 'status' => 'Active']);
    }

    private function csv(string $name, array $header, array $rows): UploadedFile
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $header, ",", "\"", "");
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($column) => $row[$column] ?? '', $header));
        }
        rewind($handle);

        return UploadedFile::fake()->createWithContent($name, stream_get_contents($handle));
    }

    /** One invoice, two lines (official fee + service with IGST). */
    private function invoiceFile(string $status = 'Overdue', string $balance = '16300', string $number = '940M001INFER612'): UploadedFile
    {
        $base = ['Invoice Date' => '05 Oct 2026', 'Invoice ID' => 'z-inv-1', 'Invoice Number' => $number, 'Invoice Status' => $status,
            'Customer ID' => 'cust-9', 'Customer Name' => 'Agnikul Cosmos', 'Due Date' => '20 Oct 2026', 'Currency Code' => 'INR',
            'Exchange Rate' => '1', 'SubTotal' => '14500', 'Total' => '16300', 'Balance' => $balance, 'Place of Supply' => 'TN',
            'CF.Application No:' => '202641012345', 'CF.Patent Office:' => 'IN', 'CF.Service Fee' => '10000', 'CF.Patent Office Fee' => '4500'];

        return $this->csv('Invoice.csv', self::INVOICE_HEADER, [
            $base + ['Item Name' => 'FER', 'Item Desc' => 'Respond to first examination report', 'Account' => 'Service Fees INR', 'Quantity' => '1', 'Item Total' => '10000', 'IGST' => '1800'],
            $base + ['Item Name' => 'POI', 'Item Desc' => 'Fee fixed by and payable to Indian Patent Office', 'Account' => 'Patent Office Fees Reimbursement – India', 'Quantity' => '1', 'Item Total' => '4500'],
        ]);
    }

    private function preview(array $files): array
    {
        return $this->post('/api/finance/zoho-export/preview', ['files' => $files], ['Accept' => 'application/json'])->assertOk()->json();
    }

    public function test_preview_writes_nothing_and_import_fills_the_register_once(): void
    {
        $preview = $this->preview([$this->invoiceFile()]);
        $this->assertSame(1, $preview['reports'][0]['new']);
        $this->assertSame(0, PatentInvoiceIn::count());

        $this->postJson('/api/finance/zoho-export/import', ['token' => $preview['token']])->assertOk();
        $row = PatentInvoiceIn::where('zoho_number', '940M001INFER612')->firstOrFail();
        $this->assertSame('zoho', $row->source);
        $this->assertSame($this->client->id, $row->client_id);
        $this->assertSame('10000.00', $row->service_fees);
        $this->assertSame('4500.00', $row->patent_office_fees);
        $this->assertSame('1800.00', $row->igst_amount);
        $this->assertSame('16300.00', $row->balance_due);
        $this->assertSame('202641012345', $row->patent_office_application_number);
        $this->assertSame('Tamil Nadu', $row->state_of_supply);

        // The same file again: identical, nothing added.
        $again = $this->preview([$this->invoiceFile()]);
        $this->assertSame(1, $again['reports'][0]['identical']);
        $this->assertEquals(['count' => 1, 'billed' => 16300, 'outstanding' => 16300, 'collected' => 0], $again['reconciliation']['invoices']['portal']);

        // Zoho marked it paid: updated in place.
        $paid = $this->preview([$this->invoiceFile('Closed', '0')]);
        $this->assertSame(1, $paid['reports'][0]['changed']);
        $this->postJson('/api/finance/zoho-export/import', ['token' => $paid['token']])->assertOk();
        $this->assertSame(1, PatentInvoiceIn::count());
        $this->assertSame('Confirmed', $row->fresh()->payment_status);
    }

    public function test_payments_get_their_dates_from_the_payment_export(): void
    {
        $first = $this->preview([$this->invoiceFile('PartiallyPaid', '6300')]);
        $this->postJson('/api/finance/zoho-export/import', ['token' => $first['token']])->assertOk();
        $row = PatentInvoiceIn::firstOrFail();
        $this->assertTrue(RegisterPayment::where('patent_invoice_in_id', $row->id)->first()->estimated);

        $payments = $this->csv('Customer_Payment.csv', self::PAYMENT_HEADER, [[
            'Payment Number' => '42', 'CustomerPayment ID' => 'z-pay-1', 'Mode' => 'Bank Transfer', 'CustomerID' => 'cust-9', 'Exchange Rate' => '1',
            'Amount' => '10000', 'Unused Amount' => '0', 'Currency Code' => 'INR', 'Customer Name' => 'Agnikul Cosmos', 'Date' => '12 Oct 2026',
            'Amount Applied to Invoice' => '10000', 'Invoice Number' => '940M001INFER612',
        ]]);
        $preview = $this->preview([$payments]);
        $this->assertSame(1, $preview['reports'][0]['new']);
        $this->postJson('/api/finance/zoho-export/import', ['token' => $preview['token']])->assertOk();

        $recorded = RegisterPayment::where('patent_invoice_in_id', $row->id)->get();
        $this->assertCount(1, $recorded);   // the estimate was replaced by the real payment
        $this->assertSame('2026-10-12', $recorded[0]->payment_date->toDateString());
        $this->assertFalse($recorded[0]->estimated);
    }

    public function test_uin_links_the_case_and_a_case_created_later_picks_up_its_invoices(): void
    {
        Sanctum::actingAs(User::create(['name' => 'Partner', 'email' => 'p@zexp.test', 'password' => bcrypt('password'), 'role' => 'partner', 'status' => 'Active']));
        $preview = $this->preview([$this->invoiceFile()]);
        $this->postJson('/api/finance/zoho-export/import', ['token' => $preview['token']])->assertOk();
        $row = PatentInvoiceIn::firstOrFail();
        $this->assertNull($row->project_id);

        // The case is entered in the portal afterwards: its invoice links and the form fills from it.
        $id = $this->postJson('/api/projects', [
            'record_mode' => 'existing', 'project_code' => '940M001INFER', 'docket_number' => '940M001INFER', 'client_id' => $this->client->id,
            'project_name' => 'Cryogenic Engine', 'project_type' => 'Patent', 'patent_office_code' => 'IN', 'service_code' => 'FER',
        ])->assertCreated()->json('id');
        $row->refresh();
        $this->assertSame($id, $row->project_id);
        $this->assertSame('Cryogenic Engine', $row->invention_title);
    }

    public function test_only_finance_roles_can_import(): void
    {
        Sanctum::actingAs(User::create(['name' => 'Para', 'email' => 'para@zexp.test', 'password' => bcrypt('password'), 'role' => 'paralegal', 'status' => 'Active']));
        $this->post('/api/finance/zoho-export/preview', ['files' => [$this->invoiceFile()]], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_a_file_that_is_not_a_zoho_export_is_refused(): void
    {
        $bad = UploadedFile::fake()->createWithContent('other.csv', "Name,Amount\nX,1\n");
        $this->post('/api/finance/zoho-export/preview', ['files' => [$bad]], ['Accept' => 'application/json'])->assertStatus(422);
    }

    private const CREDIT_NOTE_HEADER = ['Credit Note Date', 'CreditNotes ID', 'Credit Note Number', 'Credit Note Status', 'Customer Name', 'Customer ID',
        'Currency Code', 'Exchange Rate', 'Total', 'Balance', 'Associated Invoice Number', 'Applied Invoice Number', 'Item Name', 'Item Total'];

    private const CONTACT_HEADER = ['Display Name', 'Customer Number', 'Company Name', 'Currency Code', 'Status', 'Opening Balance',
        'Opening Balance Exchange Rate', 'GST Treatment', 'GST Identification Number (GSTIN)', 'Contact ID', 'Contact Type', 'EmailID', 'MobilePhone'];

    private function import(array $files, bool $removeMissing = false): array
    {
        $preview = $this->preview($files);

        return $this->postJson('/api/finance/zoho-export/import', ['token' => $preview['token'], 'remove_missing' => $removeMissing])->assertOk()->json();
    }

    public function test_credit_notes_settle_their_invoice_and_reach_the_ledger(): void
    {
        $this->import([$this->invoiceFile('Closed', '0')]);
        $row = PatentInvoiceIn::firstOrFail();
        $this->assertTrue(RegisterPayment::where('patent_invoice_in_id', $row->id)->first()->estimated);   // paid per Zoho, no record yet

        $notes = $this->csv('Credit_Note.csv', self::CREDIT_NOTE_HEADER, [
            ['Credit Note Date' => '29 Oct 2026', 'CreditNotes ID' => 'z-cn-1', 'Credit Note Number' => 'CN-00001', 'Credit Note Status' => 'Closed',
                'Customer Name' => 'Agnikul Cosmos', 'Customer ID' => 'cust-9', 'Currency Code' => 'INR', 'Exchange Rate' => '1', 'Total' => '16300.00',
                'Balance' => '0.00', 'Associated Invoice Number' => '940M001INFER612', 'Applied Invoice Number' => '940M001INFER612', 'Item Name' => 'FER', 'Item Total' => '10000'],
            ['CreditNotes ID' => 'z-cn-1', 'Credit Note Number' => 'CN-00001', 'Total' => '16300.00', 'Balance' => '0.00', 'Item Name' => 'POI', 'Item Total' => '4500'],
        ]);
        $preview = $this->preview([$notes]);
        $this->assertSame('creditnotes', $preview['reports'][0]['kind']);
        $this->assertSame(1, $preview['reports'][0]['new']);
        $this->postJson('/api/finance/zoho-export/import', ['token' => $preview['token']])->assertOk();

        $lines = RegisterPayment::where('patent_invoice_in_id', $row->id)->get();
        $this->assertCount(1, $lines);
        $this->assertSame('credit_note', $lines[0]->kind);
        $this->assertSame('16300.00', $lines[0]->amount_bcy);
        $this->assertTrue(\App\Models\ClientLedger::where('client_id', $this->client->id)->where('document_type', 'Credit note')->exists());

        // The same file again changes nothing.
        $this->assertSame(1, $this->preview([$notes])['reports'][0]['identical']);
    }

    public function test_customers_update_opening_balances_and_never_edit_portal_clients(): void
    {
        $this->client->update(['zoho_contact_id' => 'cust-9']);
        $before = $this->client->fresh()->only(['legal_name', 'company_name', 'state', 'zoho_contact_id', 'updated_at']);
        \App\Models\ZohoOpeningBalance::create(['scope' => app(\App\Services\ZohoBooksService::class)->scope(), 'zoho_contact_id' => 'cust-9',
            'client_id' => $this->client->id, 'location_id' => 'loc-1', 'balance_date' => '2026-03-31', 'amount' => 4000, 'balance' => 1000, 'currency' => 'INR']);

        $contacts = $this->csv('Contacts.csv', self::CONTACT_HEADER, [
            ['Display Name' => 'Agnikul Cosmos Pvt Ltd (renamed in Zoho)', 'Customer Number' => 'CUS-9', 'Company Name' => 'Agnikul Cosmos Pvt Ltd', 'Currency Code' => 'INR',
                'Status' => 'Active', 'Opening Balance' => '5000.00', 'Opening Balance Exchange Rate' => '1', 'Contact ID' => 'cust-9', 'Contact Type' => 'customer',
                'EmailID' => 'person@example.com', 'MobilePhone' => '+91 90000 00000'],
            ['Display Name' => 'New Customer', 'Company Name' => 'New Customer', 'Currency Code' => 'USD', 'Status' => 'Active', 'Opening Balance' => '0',
                'Contact ID' => 'cust-10', 'Contact Type' => 'customer'],
        ]);
        $done = $this->import([$contacts]);
        $report = $done['reports'][0];
        $this->assertSame('contacts', $report['kind']);
        $this->assertSame(1, $report['unlinked']);   // cust-10 has no portal client

        // Opening balance: new amount, the 3,000 already paid is kept.
        $opening = \App\Models\ZohoOpeningBalance::withoutGlobalScopes()->where('zoho_contact_id', 'cust-9')->firstOrFail();
        $this->assertSame('5000.00', $opening->amount);
        $this->assertSame('2000.00', $opening->balance);
        $this->assertSame(1, \App\Models\ZohoOpeningBalance::withoutGlobalScopes()->count());

        // The portal client is untouched; no personal contact details are kept from the file.
        $this->assertEquals($before, $this->client->fresh()->only(['legal_name', 'company_name', 'state', 'zoho_contact_id', 'updated_at']));
        $snapshot = \App\Models\ZohoSnapshot::where('resource', 'contacts')->where('remote_id', 'cust-9')->firstOrFail();
        $this->assertStringNotContainsString('person@example.com', (string) $snapshot->detail);
        $this->assertSame(1, \App\Models\Client::count());
    }

    public function test_a_full_export_removes_records_zoho_no_longer_has_only_when_plausible(): void
    {
        $scope = app(\App\Services\ZohoBooksService::class)->scope();
        $rows = [];
        foreach (range(1, 10) as $n) {
            $rows[] = ['Invoice Date' => '05 Oct 2026', 'Invoice ID' => "z-inv-{$n}", 'Invoice Number' => sprintf('940M%03dINFER612', $n), 'Invoice Status' => 'Overdue',
                'Customer ID' => 'cust-9', 'Customer Name' => 'Agnikul Cosmos', 'Currency Code' => 'INR', 'Exchange Rate' => '1', 'SubTotal' => '1000',
                'Total' => '1000', 'Balance' => '1000', 'Item Name' => 'FER', 'Account' => 'Service Fees INR', 'Quantity' => '1', 'Item Total' => '1000'];
        }
        $file = fn () => $this->csv('Invoice.csv', self::INVOICE_HEADER, $rows);
        $this->import([$file()]);
        \App\Models\ZohoInvoice::create(['scope' => $scope, 'zoho_id' => 'z-gone', 'zoho_type' => 'invoice', 'number' => '940M099INFER612', 'status' => 'overdue',
            'total' => 500, 'balance' => 500, 'currency' => 'INR', 'txn_date' => '2026-10-01']);

        // Without asking: reported, kept.
        $preview = $this->preview([$file()]);
        $this->assertSame(1, $preview['reports'][0]['missing']);
        $this->assertTrue($preview['reports'][0]['removable']);
        $this->assertSame(['940M099INFER612'], $preview['reports'][0]['missing_sample']);

        // A full export: removed through the deletion path.
        $done = $this->import([$file()], true);
        $this->assertSame(1, $done['reports'][0]['removed']);
        $this->assertSoftDeleted('zoho_invoices', ['zoho_id' => 'z-gone']);
        $this->assertDatabaseHas('zoho_invoices', ['zoho_id' => 'z-inv-1', 'deleted_at' => null]);

        // A partial export (one invoice of ten): nothing is removed even when asked.
        $partial = $this->import([$this->csv('Invoice.csv', self::INVOICE_HEADER, [$rows[0]])], true);
        $this->assertFalse($partial['reports'][0]['removable']);
        $this->assertSame(0, $partial['reports'][0]['removed']);
        $this->assertSame(10, \App\Models\ZohoInvoice::where('zoho_type', 'invoice')->count());

        $this->assertArrayHasKey('invoices', $this->getJson('/api/finance/zoho-export/status')->assertOk()->json('last_import'));
    }
}
