<?php

namespace Tests\Feature;

use App\Jobs\ZohoSettlementWriteback;
use App\Models\Client;
use App\Models\ClientLedger;
use App\Models\Employee;
use App\Models\PatentInvoiceIn;
use App\Models\RegisterPayment;
use App\Models\User;
use App\Services\RegisterPaymentsImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Mark paid / waive from Pending Payments, how every figure follows, and the signed payment reports. */
class SettlementAndPaymentReportTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private PatentInvoiceIn $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Client::create(['client_code' => '831M', 'client_type' => 'organization', 'legal_name' => 'Sigma Labs', 'company_name' => 'Sigma Labs',
            'nationality' => 'India', 'status' => 'Active', 'contact_email' => 'ap@sigma.test']);
        $this->invoice = PatentInvoiceIn::create(['type' => 'invoice', 'status' => 'Sent', 'source' => 'manual', 'client_id' => $this->client->id,
            'invoice_uin' => '831M001INCPT/1', 'invoice_amount' => 10000, 'total_bcy' => 10000, 'balance_due' => 10000, 'exchange_rate' => 1,
            'payment_status' => 'Pending', 'invoice_date' => now()->subDays(40)->toDateString(), 'due_date' => now()->subDays(10)->toDateString(), 'currency' => 'INR']);
    }

    private function user(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role).' '.$email, 'email' => $email, 'password' => bcrypt('password'), 'role' => $role, 'status' => 'Active']);
    }

    public function test_finance_marks_paid_and_every_figure_follows(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user('finance', 'f@set.test'));
        $this->postJson("/api/pending-payments/{$this->invoice->id}/mark-paid", ['amount' => 4000, 'date' => now()->toDateString(), 'mode' => 'UPI', 'reference' => 'UTR123'])
            ->assertOk()->assertJsonPath('invoice.payment_status', 'Partially Paid');
        $this->assertEquals(6000, (float) $this->invoice->fresh()->balance_due);
        $this->assertSame('portal', RegisterPayment::where('patent_invoice_in_id', $this->invoice->id)->value('source'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'mark_paid', 'subject_id' => $this->invoice->id]);
        $this->assertTrue(ClientLedger::where('client_id', $this->client->id)->where('document_type', 'Payment')->where('credit', 4000)->exists());
        Queue::assertNothingPushed();   // a portal-raised invoice has no Zoho link: nothing to write back

        $stats = $this->getJson('/api/financial/stats')->assertOk()->json();
        $this->assertEquals(4000, $stats['total_received']);
        $this->assertEquals(6000, $stats['total_outstanding']);

        $this->postJson("/api/pending-payments/{$this->invoice->id}/mark-paid", ['amount' => 7000, 'date' => now()->toDateString(), 'mode' => 'UPI'])->assertStatus(422);

        Sanctum::actingAs($this->user('associate', 'a@set.test'));
        $this->postJson("/api/pending-payments/{$this->invoice->id}/mark-paid", ['amount' => 100, 'date' => now()->toDateString(), 'mode' => 'UPI'])->assertForbidden();
    }

    public function test_waiver_needs_a_partner_and_is_not_counted_as_received(): void
    {
        $finance = $this->user('finance', 'f2@set.test');
        Sanctum::actingAs($finance);
        $this->postJson("/api/pending-payments/{$this->invoice->id}/waiver", ['amount' => 10000, 'reason' => 'Goodwill'])->assertOk();
        $this->assertSame('waiver_requested', $this->invoice->fresh()->settlement);
        $this->assertEquals(10000, (float) $this->invoice->fresh()->balance_due);   // nothing changes until approved
        $this->postJson("/api/pending-payments/{$this->invoice->id}/waiver/decision", ['approve' => true])->assertForbidden();

        Sanctum::actingAs($this->user('partner', 'p@set.test'));
        $this->postJson("/api/pending-payments/{$this->invoice->id}/waiver/decision", ['approve' => true])->assertOk();
        $row = $this->invoice->fresh();
        $this->assertSame('Waived', $row->payment_status);
        $this->assertEquals(0, (float) $row->balance_due);
        $this->assertEquals(10000, (float) $row->waived_amount);

        // A Zoho-style gap fill must not turn the waiver into a fake payment.
        app(RegisterPaymentsImporter::class)->fillGap($row);
        $this->assertSame(0, RegisterPayment::where('patent_invoice_in_id', $row->id)->count());

        $stats = $this->getJson('/api/financial/stats')->assertOk()->json();
        $this->assertEquals(0, $stats['total_received']);
        $this->assertEquals(0, $stats['total_outstanding']);
        $this->assertTrue(ClientLedger::where('client_id', $this->client->id)->where('document_type', 'Waiver')->exists());
        $this->assertSame(0, $this->getJson('/api/pending-payments')->assertOk()->json('total'));
    }

    public function test_a_zoho_linked_payment_is_queued_for_write_back(): void
    {
        Queue::fake();
        // Without the mirror's saved hook (that runs the importer); only the link matters here.
        $mirror = \App\Models\ZohoInvoice::withoutEvents(fn () => \App\Models\ZohoInvoice::create(['scope' => 'in:1', 'zoho_id' => '9001', 'zoho_type' => 'invoice', 'zoho_contact_id' => '777', 'client_id' => $this->client->id]));
        $this->invoice->update(['zoho_invoice_id' => $mirror->id, 'source' => 'zoho']);
        Sanctum::actingAs($this->user('partner', 'p2@set.test'));
        $this->postJson("/api/pending-payments/{$this->invoice->id}/mark-paid", ['amount' => 10000, 'date' => now()->toDateString(), 'mode' => 'Bank transfer'])->assertOk();
        $this->assertSame('pending', $this->invoice->fresh()->zoho_writeback);
        Queue::assertPushed(ZohoSettlementWriteback::class, fn ($job) => $job->invoiceId === $this->invoice->id && $job->kind === 'payment');
    }

    public function test_large_pdf_renders_and_history_redownload_keeps_the_signature(): void
    {
        $partner = $this->user('partner', 'p4@set.test');
        $employee = Employee::create(['employee_code' => 'EMP-2026-0098', 'user_id' => $partner->id, 'full_name' => 'Partner', 'work_email' => 'p4@set.test',
            'status' => 'Active', 'date_of_joining' => '2024-01-01']);
        $employee->setInternalSignaturePin('2468');
        $employee->save();
        // 400 payments: more than the PDF shows; it must still render (it ran out of memory before).
        foreach (range(1, 400) as $n) {
            RegisterPayment::create(['patent_invoice_in_id' => $this->invoice->id, 'client_id' => $this->client->id, 'source' => 'zoho', 'zoho_payment_id' => "p{$n}",
                'payment_date' => now()->subDays($n % 300)->toDateString(), 'amount' => 1, 'currency' => 'INR', 'exchange_rate' => 1, 'amount_bcy' => 1]);
        }
        Sanctum::actingAs($partner);
        $pdf = $this->post('/api/reports/download', ['type' => 'payment-collection', 'file' => 'pdf', 'pin' => '2468'], ['Accept' => 'application/json'])->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());

        $export = \App\Models\ReportExport::latest('id')->first();
        $code = \App\Models\InternalDocumentSignature::where('document_type', 'report_export')->where('document_id', $export->id)->value('verification_code');
        $this->post("/api/reports/history/{$export->id}/download", ['file' => 'xlsx'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame($code, \App\Models\InternalDocumentSignature::where('document_type', 'report_export')->where('document_id', $export->id)->value('verification_code'));
        $this->getJson("/api/reports/verify/{$code}")->assertOk()->assertJsonPath('valid', true);

        $png = app(\App\Services\Reports\SignatureSeal::class)->png(true);
        $this->assertStringStartsWith("\x89PNG", $png);
    }

    public function test_every_report_downloads_on_the_letterhead_and_signs(): void
    {
        // 26 downloads in a row: the per-minute download limit is not what this test checks.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $admin = $this->user('super_admin', 'sa@set.test');
        $employee = Employee::create(['employee_code' => 'EMP-2026-0097', 'user_id' => $admin->id, 'full_name' => 'Admin', 'work_email' => 'sa@set.test',
            'status' => 'Active', 'date_of_joining' => '2024-01-01']);
        $employee->setInternalSignaturePin('1357');
        $employee->save();
        Sanctum::actingAs($admin);
        $types = ['client-portfolio', 'matter-status', 'financial-summary', 'hrms', 'ip-deadline', 'productivity', 'tracker-workload', 'overdue-cases',
            'deadline-forecast', 'payment-collection', 'zoho-summary', 'payroll-cost-summary', 'tds-pf-esi-summary'];
        foreach ($types as $type) {
            $pdf = $this->post('/api/reports/download', ['type' => $type, 'file' => 'pdf'], ['Accept' => 'application/json']);
            $pdf->assertOk();
            $this->assertStringStartsWith('%PDF', (string) $pdf->getContent(), $type);
            $this->post('/api/reports/download', ['type' => $type, 'file' => 'csv', 'date' => now()->toDateString()], ['Accept' => 'application/json'])->assertOk();
        }

        // A generic report: Excel has Summary + Data, signing works and verifies, history keeps the code.
        $xlsx = $this->post('/api/reports/download', ['type' => 'client-portfolio', 'file' => 'xlsx', 'pin' => '1357'], ['Accept' => 'application/json'])->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'gen').'.xlsx';
        file_put_contents($path, $xlsx->getContent());
        $this->assertSame(['Summary', 'Data'], \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheetNames());
        $export = \App\Models\ReportExport::latest('id')->first();
        $code = \App\Models\InternalDocumentSignature::where('document_type', 'report_export')->where('document_id', $export->id)->value('verification_code');
        $this->assertNotNull($code);
        $this->getJson("/api/reports/verify/{$code}")->assertOk()->assertJsonPath('valid', true);
        $this->post("/api/reports/history/{$export->id}/download", ['file' => 'pdf'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(1, \App\Models\InternalDocumentSignature::where('document_type', 'report_export')->where('document_id', $export->id)->count());

        // Role access is the same as the report module's.
        Sanctum::actingAs($this->user('hr', 'hr@set.test'));
        $this->post('/api/reports/download', ['type' => 'hrms', 'file' => 'csv'], ['Accept' => 'application/json'])->assertOk();
        // A paralegal sees all cases but finance only when on Settings → Financial access.
        Sanctum::actingAs($this->user('paralegal', 'para@set.test'));
        $this->post('/api/reports/download', ['type' => 'financial-summary', 'file' => 'csv'], ['Accept' => 'application/json'])->assertForbidden();
        $this->post('/api/reports/download', ['type' => 'hrms', 'file' => 'csv'], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_reports_come_from_the_register_and_downloads_are_signed(): void
    {
        $partner = $this->user('partner', 'p3@set.test');
        $employee = Employee::create(['employee_code' => 'EMP-2026-0099', 'user_id' => $partner->id, 'full_name' => 'Partner', 'work_email' => 'p3@set.test',
            'status' => 'Active', 'date_of_joining' => '2024-01-01']);
        $employee->setInternalSignaturePin('4321');
        $employee->save();
        Sanctum::actingAs($partner);

        $pc = $this->postJson('/api/reports/generate', ['type' => 'payment-collection', 'format' => 'CSV'])->assertOk()->json();
        $this->assertSame('831M001INCPT/1', $pc['rows'][0]['Invoice']);
        $this->assertSame(10, $pc['rows'][0]['Days overdue']);
        $this->assertSame(1, $this->postJson('/api/reports/generate', ['type' => 'financial-summary', 'format' => 'CSV', 'client_code' => '831M'])->json('total'));

        $this->post('/api/reports/download', ['type' => 'payment-collection', 'file' => 'xlsx', 'pin' => '0000'], ['Accept' => 'application/json'])->assertStatus(422);
        $xlsx = $this->post('/api/reports/download', ['type' => 'payment-collection', 'file' => 'xlsx', 'pin' => '4321'], ['Accept' => 'application/json'])->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $xlsx->headers->get('content-type'));
        $path = tempnam(sys_get_temp_dir(), 'rep').'.xlsx';
        file_put_contents($path, $xlsx->getContent());
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $this->assertSame(['Summary', 'Follow-up', 'Payments', 'Waivers', 'Ageing', 'By client'], $book->getSheetNames());
        $this->assertNotNull($book->getSheetByName('Follow-up')->getAutoFilter()->getRange());

        $code = \App\Models\InternalDocumentSignature::where('document_type', 'report_export')->value('verification_code');
        $this->getJson("/api/reports/verify/{$code}")->assertOk()->assertJsonPath('valid', true);

        $this->post('/api/reports/download', ['type' => 'financial-summary', 'file' => 'pdf'], ['Accept' => 'application/json'])->assertOk();
        $csv = $this->post('/api/reports/download', ['type' => 'payment-collection', 'file' => 'csv', 'date' => now()->subDays(40)->toDateString()], ['Accept' => 'application/json'])->assertOk();
        $this->assertStringContainsString('831M001INCPT/1', $csv->streamedContent());
        $this->assertStringContainsString('UNSIGNED DRAFT', $csv->streamedContent());
    }
}
