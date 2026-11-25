<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientLedger;
use App\Models\FinanceLockFlag;
use App\Models\PatentInvoiceIn;
use App\Models\QuoteSendApproval;
use App\Models\TdsRecord;
use App\Models\User;
use App\Models\ZohoInvoice;
use App\Models\ZohoSnapshot;
use App\Services\Form26asParser;
use App\Services\TdsTracker;
use App\Services\ZohoBooksService;
use App\Services\ZohoQuoteWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Month close, quote approval, the ledger check, exchange differences and the TDS tracker. */
class FinanceControlsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->client = Client::create(['client_code' => '445M', 'client_type' => 'organization', 'legal_name' => 'Sustainable Reference Analytics Private Limited',
            'company_name' => 'Sustainable Reference Analytics', 'nationality' => 'India', 'status' => 'Active', 'zoho_contact_id' => 'cust-445']);
    }

    private function user(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $email, 'password' => bcrypt('password'), 'role' => $role, 'status' => 'Active']);
    }

    private function invoice(string $date, float $amount = 10000): PatentInvoiceIn
    {
        return PatentInvoiceIn::create(['type' => 'invoice', 'status' => 'Sent', 'source' => 'manual', 'client_id' => $this->client->id,
            'invoice_uin' => '445M001INCPT/1', 'invoice_amount' => $amount, 'total_bcy' => $amount, 'balance_due' => $amount, 'exchange_rate' => 1,
            'payment_status' => 'Pending', 'invoice_date' => $date, 'due_date' => $date, 'currency' => 'INR']);
    }

    public function test_a_closed_month_refuses_portal_edits_and_flags_import_changes(): void
    {
        $finance = $this->user('finance', 'f@ctl.test');
        Sanctum::actingAs($finance);
        $month = now()->subMonths(2);
        $invoice = $this->invoice($month->copy()->startOfMonth()->addDays(2)->toDateString());

        $this->postJson('/api/finance/controls/lock/'.now()->format('Y-m'))->assertStatus(422);   // only past months
        $this->postJson('/api/finance/controls/lock/'.$month->format('Y-m'))->assertOk();

        // Paying with a date in the closed month is refused; today is fine.
        $this->postJson("/api/pending-payments/{$invoice->id}/mark-paid", ['amount' => 100, 'date' => $month->copy()->startOfMonth()->addDays(5)->toDateString(), 'mode' => 'UPI'])
            ->assertStatus(423);
        $this->postJson("/api/pending-payments/{$invoice->id}/mark-paid", ['amount' => 100, 'date' => now()->toDateString(), 'mode' => 'UPI'])->assertOk();
        $this->putJson("/api/patent-invoices/in/{$invoice->id}", ['invention_title' => 'Changed'])->assertStatus(423);

        // A change that still reaches the closed month (an import) is flagged, then reviewed.
        $invoice->update(['balance_due' => 9800]);
        $flag = FinanceLockFlag::where('month', $month->format('Y-m'))->firstOrFail();
        $this->assertStringContainsString('balance_due', $flag->what);
        $this->postJson('/api/finance/controls/review', ['ids' => [$flag->id]])->assertOk()->assertJsonPath('reviewed', 1);

        // Accounts cannot reopen; a partner can, with a reason.
        $this->postJson('/api/finance/controls/unlock/'.$month->format('Y-m'), ['reason' => 'x'])->assertForbidden();
        Sanctum::actingAs($this->user('partner', 'p@ctl.test'));
        $this->postJson('/api/finance/controls/unlock/'.$month->format('Y-m'), [])->assertStatus(422);
        $this->postJson('/api/finance/controls/unlock/'.$month->format('Y-m'), ['reason' => 'Correction from the CA'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'finance_month_unlocked']);
    }

    public function test_a_quote_goes_to_zoho_only_with_accounts_approval_of_that_exact_draft(): void
    {
        $writer = app(ZohoQuoteWriter::class);
        $manager = $this->user('manager', 'm@ctl.test');
        $finance = $this->user('finance', 'f2@ctl.test');
        $v1 = str_repeat('a', 64);
        $v2 = str_repeat('b', 64);

        // Not Accounts and nothing approved: refused.
        try {
            $writer->approveForSend('in', 7, 'cust-445', $v1, $manager);
            $this->fail('A manager cannot approve their own quote.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // Requested, then approved by Accounts on the controls page.
        $request = QuoteSendApproval::create(['quote_kind' => 'in', 'quote_id' => 7, 'customer_id' => 'cust-445', 'version' => $v1, 'requested_by_id' => $manager->id, 'status' => 'pending']);
        Sanctum::actingAs($finance);
        $this->postJson("/api/finance/quote-approvals/{$request->id}/decide", ['approve' => true])->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame($request->id, $writer->approveForSend('in', 7, 'cust-445', $v1, $manager)->id);

        // The quote changed after approval: needs approving again.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $writer->approveForSend('in', 7, 'cust-445', $v2, $manager);
    }

    public function test_accounts_sending_records_its_own_approval(): void
    {
        $finance = $this->user('finance', 'f3@ctl.test');
        $approval = app(ZohoQuoteWriter::class)->approveForSend('standard', 3, 'cust-445', str_repeat('c', 64), $finance);
        $this->assertSame('approved', $approval->status);
        $this->assertSame($finance->id, $approval->decided_by_id);
    }

    public function test_the_ledger_check_tells_accounts_when_a_client_differs(): void
    {
        $finance = $this->user('finance', 'f4@ctl.test');
        ZohoSnapshot::create(['scope' => app(ZohoBooksService::class)->scope(), 'resource' => 'contacts', 'remote_id' => 'cust-445', 'summary' => '{}', 'fingerprint' => 'c',
            'detail' => json_encode(['contact_id' => 'cust-445', 'outstanding_receivable_amount' => 5000, 'unused_credits_receivable_amount' => 0])]);

        $this->artisan('finance:ledger-vs-zoho --alert')->assertExitCode(0);

        $last = \App\Services\LedgerCheck::last();
        $this->assertSame(1, $last['checked']);
        $this->assertSame('445M', $last['differ'][0]['code']);
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('ip_notifications')->where('user_id', $finance->id)->where('type', 'ledger_check')->exists());
    }

    public function test_an_exchange_difference_keeps_a_foreign_client_ledger_on_the_invoice_rate(): void
    {
        $scope = app(ZohoBooksService::class)->scope();
        $this->client->update(['client_code' => '940Y']);
        ZohoSnapshot::create(['scope' => $scope, 'resource' => 'invoices', 'remote_id' => 'inv-usd', 'summary' => '{}', 'fingerprint' => 'i',
            'detail' => json_encode(['line_items' => [['name' => 'Professional fees', 'item_total' => 1000]], 'taxes' => [], 'exchange_rate' => 80])]);
        ZohoInvoice::create(['scope' => $scope, 'zoho_id' => 'inv-usd', 'zoho_type' => 'invoice', 'number' => '940Y001USFER100', 'status' => 'paid',
            'total' => 1000, 'balance' => 0, 'currency' => 'USD', 'txn_date' => '2026-06-01', 'zoho_contact_id' => 'cust-445']);
        ZohoSnapshot::create(['scope' => $scope, 'resource' => 'customerpayments', 'remote_id' => 'pay-usd', 'summary' => '{}', 'fingerprint' => 'p',
            'detail' => json_encode(['payment_id' => 'pay-usd', 'payment_number' => 'P900', 'date' => '2026-07-01', 'amount' => 1000, 'unused_amount' => 0,
                'exchange_rate' => 85, 'currency_code' => 'USD', 'customer_id' => 'cust-445', 'invoices' => [['invoice_id' => 'inv-usd', 'amount_applied' => 1000]]])]);

        $fx = ClientLedger::where('client_id', $this->client->id)->where('document_type', 'Exchange difference')->firstOrFail();
        $this->assertSame('5000.00', $fx->debit);   // received 85,000 for an 80,000 invoice: a gain
        $this->assertSame('0.00', app(\App\Services\ClientStatement::class)->lines($this->client->id)->last()->balance);
    }

    public function test_tds_lines_are_tracked_and_matched_to_26as(): void
    {
        $scope = app(ZohoBooksService::class)->scope();
        ZohoInvoice::create(['scope' => $scope, 'zoho_id' => 'inv-6', 'zoho_type' => 'invoice', 'number' => '445M006INPRV349', 'status' => 'paid',
            'total' => 37000, 'balance' => 0, 'currency' => 'INR', 'txn_date' => '2026-08-20', 'zoho_contact_id' => 'cust-445']);
        ZohoSnapshot::create(['scope' => $scope, 'resource' => 'customerpayments', 'remote_id' => 'pay-377', 'summary' => '{}', 'fingerprint' => 'p',
            'detail' => json_encode(['payment_id' => 'pay-377', 'payment_number' => 'P377', 'date' => '2026-08-25', 'amount' => 34000, 'unused_amount' => 0,
                'exchange_rate' => 1, 'currency_code' => 'INR', 'customer_id' => 'cust-445',
                'invoices' => [['invoice_id' => 'inv-6', 'amount_applied' => 34000, 'tax_amount_withheld' => 3000]]])]);

        $record = TdsRecord::firstOrFail();
        $this->assertSame('2026-27', $record->fy);
        $this->assertSame('Q2', $record->quarter);
        $this->assertSame('3000.00', $record->amount);
        $this->assertSame('pending', $record->status);

        // A TRACES 26AS text file: the deductor is matched to the client by name, the credit to the line.
        $text = implode("\n", [
            'PART I - Details of Tax Deducted at Source',
            'Sr. No.^Name of Deductor^TAN of Deductor^^^^^Total Amount Paid / Credited^Total Tax Deducted^Total TDS Deposited',
            '1^SUSTAINABLE REFERENCE ANALYTICS PRIVATE LIMITED^MUMS12345A^^^^^37000.00^3000.00^3000.00',
            '^Sr. No.^Section^Transaction Date^Status of Booking^Date of Booking^Remarks^Amount Paid / Credited^Tax Deducted^TDS Deposited',
            '^1^194J^25-Aug-2026^F^30-Sep-2026^^37000.00^3000.00^3000.00',
            '^2^194J^02-Sep-2026^F^30-Sep-2026^^5000.00^500.00^500.00',
            'PART II - Details of Tax Deducted at Source for 15G / 15H',
            '1^SOMEONE ELSE^ABCD12345E^^^^^1000.00^100.00^100.00',
        ]);
        $path = tempnam(sys_get_temp_dir(), '26as');
        file_put_contents($path, $text);
        $entries = app(Form26asParser::class)->parse($path);
        $this->assertCount(2, $entries);   // Part II ignored

        $finance = $this->user('finance', 'f5@ctl.test');
        Sanctum::actingAs($finance);
        $upload = $this->post('/api/tds/26as', ['file' => UploadedFile::fake()->createWithContent('26AS.txt', $text)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame(2, $upload['entries']);
        $this->assertSame(1, $upload['matched']);
        $this->assertSame('in_26as', $record->fresh()->status);

        $page = $this->getJson('/api/tds')->assertOk()->json();
        $this->assertEquals(3000, $page['summary']['in_26as']);
        $this->assertCount(1, $page['unmatched_26as']);   // the ₹500 credit has no TDS line in the portal
        $this->assertSame('MUMS12345A', $page['deductors'][0]['tan']);

        $this->putJson("/api/tds/{$record->id}/form16a", ['received_on' => '2026-11-15', 'certificate_no' => 'ABCDE12'])->assertOk();
        $this->assertSame('ABCDE12', $record->fresh()->form16a_certificate_no);

        // Not Accounts: may look (own scope), may not upload.
        Sanctum::actingAs($this->user('manager', 'm5@ctl.test'));
        $this->post('/api/tds/26as', ['file' => UploadedFile::fake()->createWithContent('26AS.txt', $text)], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertSame(['fy' => '2026-27', 'quarter' => 'Q4'], TdsTracker::period('2027-02-10'));
    }
}
