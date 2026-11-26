<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\MatterCost;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Zoho Bill / Expense exports become matter costs: matter accounts only, tied to the case by docket code. */
class ZohoCostImportTest extends TestCase
{
    use RefreshDatabase;

    private const BILL_HEADER = ['Bill Date', 'Bill ID', 'Vendor Name', 'Bill Number', 'Currency Code', 'Exchange Rate', 'Total', 'Balance', 'Vendor Notes',
        'Bill Status', 'Item Name', 'Account', 'Description', 'Item Total'];

    private const EXPENSE_HEADER = ['Expense Date', 'Expense Description', 'Expense Account', 'Vendor', 'Currency Code', 'Exchange Rate', 'Expense Amount', 'Total',
        'Tax Amount', 'Reference#', 'Customer Number', 'Expense Reference ID'];

    private Project $case;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::create(['name' => 'Accounts', 'email' => 'f@zc.test', 'password' => bcrypt('password'), 'role' => 'finance', 'status' => 'Active']));
        $client = Client::create(['client_code' => '329M', 'client_type' => 'organization', 'legal_name' => 'Kappa Corp', 'company_name' => 'Kappa',
            'nationality' => 'India', 'status' => 'Active']);
        $this->case = Project::create(['project_code' => '329M001JPRNF', 'docket_number' => '329M001JPRNF', 'client_id' => $client->id,
            'project_type' => 'Patent', 'project_name' => 'Kappa JP', 'patent_office_code' => 'JP', 'service_code' => 'RNF', 'status' => 'Open']);
    }

    private function csv(string $name, array $header, array $rows): UploadedFile
    {
        $h = fopen('php://temp', 'r+');
        fputcsv($h, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($h, array_map(fn ($c) => $row[$c] ?? '', $header), ',', '"', '');
        }
        rewind($h);

        return UploadedFile::fake()->createWithContent($name, stream_get_contents($h));
    }

    private function import(array $files): array
    {
        $preview = $this->post('/api/finance/zoho-export/preview', ['files' => $files], ['Accept' => 'application/json'])->assertOk()->json();

        return $this->postJson('/api/finance/zoho-export/import', ['token' => $preview['token']])->assertOk()->json('reports.0');
    }

    private function bills(): UploadedFile
    {
        $base = ['Bill Date' => '12 Oct 2026', 'Bill ID' => 'b-1', 'Vendor Name' => 'Tokyo IP Partners', 'Bill Number' => 'TIP-77', 'Currency Code' => 'INR',
            'Exchange Rate' => '1', 'Vendor Notes' => 'Annuity 329M001JPRNF409', 'Bill Status' => 'Paid'];

        return $this->csv('Bill.csv', self::BILL_HEADER, [
            $base + ['Item Name' => 'Agent fee', 'Account' => 'Service Fee - Foreign Attorneys', 'Description' => 'JP annuity agent fee', 'Item Total' => '12000'],
            $base + ['Item Name' => 'JPO fee', 'Account' => 'Patent Office Fees Reimbursement – Foreign', 'Description' => 'JPO annuity', 'Item Total' => '8000'],
            $base + ['Item Name' => 'Cab', 'Account' => 'Travel & conveyance', 'Item Total' => '500'],
            ['Bill Date' => '15 Oct 2026', 'Bill ID' => 'b-2', 'Vendor Name' => 'Draft vendor', 'Bill Number' => 'D-1', 'Currency Code' => 'INR', 'Exchange Rate' => '1',
                'Bill Status' => 'Draft', 'Account' => 'India Attorney Fees', 'Item Total' => '9000'],
            ['Bill Date' => '16 Oct 2026', 'Bill ID' => 'b-3', 'Vendor Name' => 'Delhi Counsel', 'Bill Number' => 'DC-9', 'Currency Code' => 'INR', 'Exchange Rate' => '1',
                'Bill Status' => 'Open', 'Account' => 'India Attorney Fees', 'Vendor Notes' => 'no code here', 'Item Total' => '3000'],
        ]);
    }

    public function test_bills_become_matter_costs_tied_to_the_case(): void
    {
        $report = $this->import([$this->bills()]);
        $this->assertSame('bills', $report['kind']);
        $this->assertSame(3, $report['new']);                 // associate + official fee of b-1, associate of b-3
        $this->assertSame(1, $report['skipped_overhead']);    // travel
        $this->assertSame(1, $report['unassigned']);          // b-3 has no docket code

        $agent = MatterCost::where('zoho_id', 'bill:b-1:associate')->firstOrFail();
        $this->assertSame($this->case->id, $agent->project_id);   // 329M001JPRNF409 → docket 329M001JPRNF
        $this->assertSame('12000.00', $agent->amount_bcy);
        $this->assertSame('zoho_bill', $agent->source);
        $this->assertSame('8000.00', MatterCost::where('zoho_id', 'bill:b-1:official_fee')->value('amount_bcy'));
        $this->assertFalse(MatterCost::where('zoho_id', 'like', 'bill:b-2%')->exists());   // draft left out

        // The case is set by hand for the unmatched bill; a re-import keeps it.
        $other = Project::create(['project_code' => '329M002INPRV', 'docket_number' => '329M002INPRV', 'client_id' => $this->case->client_id,
            'project_type' => 'Patent', 'project_name' => 'Kappa IN', 'patent_office_code' => 'IN', 'service_code' => 'PRV', 'status' => 'Open']);
        MatterCost::where('zoho_id', 'bill:b-3:associate')->update(['project_id' => $other->id]);
        $again = $this->import([$this->bills()]);
        $this->assertSame(3, $again['identical']);
        $this->assertSame($other->id, MatterCost::where('zoho_id', 'bill:b-3:associate')->value('project_id'));
    }

    public function test_a_service_code_finds_the_same_invention_at_the_same_office(): void
    {
        foreach (['940M001USCPT', '940M001INCPT', '940M001CNCPT'] as $docket) {
            Project::create(['project_code' => $docket, 'docket_number' => $docket, 'client_id' => $this->case->client_id, 'project_type' => 'Patent',
                'project_name' => $docket, 'patent_office_code' => substr($docket, 7, 2), 'service_code' => 'CPT', 'status' => 'Open']);
        }
        $found = app(\App\Services\ZohoCostImporter::class)->projectFor('Office action fee 940M001USNOA');
        $this->assertSame('940M001USCPT', $found?->docket_number);
        $this->assertNull(app(\App\Services\ZohoCostImporter::class)->projectFor('940M001EPNOA'));   // no EP case, and 940M001 alone is ambiguous
    }

    public function test_expenses_import_only_matter_accounts(): void
    {
        $report = $this->import([$this->csv('Expense.csv', self::EXPENSE_HEADER, [
            ['Expense Date' => '20 Oct 2026', 'Expense Description' => 'Stamp paper 329M001JPRNF', 'Expense Account' => 'Stamps', 'Vendor' => 'Stamp vendor',
                'Currency Code' => 'INR', 'Exchange Rate' => '1', 'Expense Amount' => '500', 'Total' => '500', 'Expense Reference ID' => 'e-1'],
            ['Expense Date' => '21 Oct 2026', 'Expense Description' => 'Taxi', 'Expense Account' => 'Travel & conveyance', 'Currency Code' => 'INR',
                'Exchange Rate' => '1', 'Expense Amount' => '300', 'Total' => '300', 'Expense Reference ID' => 'e-2'],
        ])]);
        $this->assertSame('expenses', $report['kind']);
        $this->assertSame(1, $report['new']);
        $this->assertSame(1, $report['skipped_overhead']);
        $stamp = MatterCost::where('zoho_id', 'expense:e-1')->firstOrFail();
        $this->assertSame('official_fee', $stamp->kind);
        $this->assertSame($this->case->id, $stamp->project_id);
    }
}
