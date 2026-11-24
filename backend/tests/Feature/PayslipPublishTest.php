<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\InternalDocumentSignature;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryComponent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Payslips reach employees only once signed by the payroll signatory; manual payslips; custom deductions; statements. */
class PayslipPublishTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Employee $staff;

    private User $staffUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'System Admin @ Mugil', 'email' => 'sa@pay.test', 'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'Active']);
        $signatory = Employee::create(['user_id' => $this->admin->id, 'employee_code' => 'SIG1', 'full_name' => 'Mugilvannan L', 'work_email' => 'sa@pay.test',
            'employment_status' => 'Active', 'date_of_joining' => now()->subYear(), 'base_pay_monthly' => 0, 'exclude_from_payroll' => true]);
        $signatory->setInternalSignaturePin('2580');
        $signatory->save();
        foreach (['payroll_signatory_user_id' => (string) $this->admin->id, 'payroll_signatory_name' => 'Mugilvannan L', 'payroll_signatory_designation' => 'AI Integration Engineer'] as $k => $v) {
            DB::table('system_settings')->updateOrInsert(['key' => $k], ['value' => $v, 'updated_at' => now()]);
        }
        $this->staffUser = User::create(['name' => 'Keerthana S', 'email' => 'k@pay.test', 'password' => bcrypt('x'), 'role' => 'associate', 'status' => 'Active']);
        $this->staff = Employee::create(['user_id' => $this->staffUser->id, 'employee_code' => 'A088', 'full_name' => 'Keerthana S', 'work_email' => 'k@pay.test',
            'employment_status' => 'Active', 'date_of_joining' => now()->subYear(), 'base_pay_monthly' => 50000, 'hra_monthly' => 10000]);
    }

    private function finalizedRun(string $period): PayrollRun
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/payroll/runs', ['period' => $period])->assertStatus(202)->json('run.id');
        $this->postJson("/api/payroll/runs/{$id}/submit-for-approval")->assertOk();
        $this->postJson("/api/payroll/runs/{$id}/approve")->assertOk();

        return PayrollRun::findOrFail($id);
    }

    public function test_employee_sees_a_payslip_only_after_sign_and_publish_and_it_survives_payment(): void
    {
        $run = $this->finalizedRun('2026-03');
        $slip = Payslip::where('payroll_run_id', $run->id)->where('employee_id', $this->staff->id)->firstOrFail();

        Sanctum::actingAs($this->staffUser);
        $this->assertSame(0, $this->getJson('/api/payroll/my-slips')->assertOk()->json('total'));
        $this->get("/api/payroll/payslips/{$slip->id}/pdf", ['Accept' => 'application/json'])->assertStatus(422);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/payroll/runs/{$run->id}/publish", ['pin' => '1111'])->assertStatus(422);
        $this->postJson("/api/payroll/runs/{$run->id}/publish", ['pin' => '2580'])->assertOk()->assertJsonPath('signed', 1);
        $sig = InternalDocumentSignature::where('document_type', 'payslip_monthly')->where('document_id', $slip->id)->firstOrFail();
        $this->assertSame('Mugilvannan L', $sig->signer_name);
        $this->assertSame('AI Integration Engineer', $sig->signer_designation);

        // Paying the run must not break the signature (it used to).
        $this->postJson("/api/payroll/runs/{$run->id}/pay", [])->assertOk();

        Sanctum::actingAs($this->staffUser);
        $mine = $this->getJson('/api/payroll/my-slips')->assertOk()->json();
        $this->assertSame(1, $mine['total']);
        $this->assertTrue($mine['data'][0]['signed']);
        $pdf = $this->get("/api/payroll/payslips/{$slip->id}/pdf");
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());

        // Public verification by code.
        $this->getJson("/api/payslips/verify/{$sig->verification_code}")->assertOk()->assertJsonPath('valid', true)->assertJsonPath('signed_by', 'Mugilvannan L');
        $this->get("/verify/payslip/{$sig->verification_code}")->assertOk()->assertSee('Genuine');

        // Consolidated statement: signed, verifiable.
        $statement = $this->get("/api/payroll/employees/{$this->staff->id}/payslip-summary?from=2026-01&to=2026-03");
        $statement->assertOk();
        $code = InternalDocumentSignature::where('document_type', 'payslip_statement')->latest('id')->value('verification_code');
        $this->getJson("/api/payslips/verify/{$code}")->assertOk()->assertJsonPath('valid', true)->assertJsonPath('kind', 'Payslip statement');

        // Another employee's payslip stays private.
        $other = User::create(['name' => 'Other', 'email' => 'o@pay.test', 'password' => bcrypt('x'), 'role' => 'associate', 'status' => 'Active']);
        Employee::create(['user_id' => $other->id, 'employee_code' => 'B001', 'full_name' => 'Other', 'work_email' => 'o@pay.test',
            'employment_status' => 'Active', 'date_of_joining' => now()->subYear(), 'base_pay_monthly' => 0]);
        Sanctum::actingAs($other);
        $this->get("/api/payroll/payslips/{$slip->id}/pdf", ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_editing_a_signed_payslip_hides_it_until_signed_again(): void
    {
        $run = $this->finalizedRun('2026-05');
        $slip = Payslip::where('payroll_run_id', $run->id)->where('employee_id', $this->staff->id)->firstOrFail();
        $this->postJson("/api/payroll/runs/{$run->id}/publish", ['pin' => '2580'])->assertOk();
        $this->putJson("/api/payroll/payslips/{$slip->id}", ['tds' => 1000])->assertOk();

        Sanctum::actingAs($this->staffUser);
        $this->assertSame(0, $this->getJson('/api/payroll/my-slips')->json('total'));

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/payroll/runs/{$run->id}/publish", ['pin' => '2580'])->assertOk()->assertJsonPath('signed', 1);
        Sanctum::actingAs($this->staffUser);
        $this->assertSame(1, $this->getJson('/api/payroll/my-slips')->json('total'));
    }

    public function test_manual_payslip_is_real_payroll_signed_and_published(): void
    {
        SalaryComponent::create(['name' => 'Welfare fund', 'code' => 'WELFARE', 'calc_type' => 'flat', 'category' => 'deduction', 'value' => 500, 'sequence' => 90, 'active' => true]);

        Sanctum::actingAs($this->staffUser);
        $this->postJson("/api/payroll/employees/{$this->staff->id}/manual-payslip", ['period' => '2026-07', 'pin' => '2580'])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $preview = $this->postJson("/api/payroll/employees/{$this->staff->id}/manual-payslip/preview", ['period' => '2026-07', 'tds' => 2000])->assertOk()->json();
        $this->assertNull($preview['existing']);
        $this->assertContains('WELFARE', array_column($preview['components'], 'code'));

        $this->postJson("/api/payroll/employees/{$this->staff->id}/manual-payslip", ['period' => '2026-07', 'tds' => 2000, 'pin' => '0000'])->assertStatus(422);
        $this->postJson("/api/payroll/employees/{$this->staff->id}/manual-payslip", ['period' => '2026-07', 'tds' => 2000, 'pin' => '2580'])->assertCreated();
        $run = PayrollRun::where('run_type', 'Manual')->firstOrFail();
        $this->assertSame('Finalized', $run->status);
        $slip = Payslip::where('payroll_run_id', $run->id)->firstOrFail();
        // Custom deduction reduces net pay: 50,000 + 12,500 variable + 10,000 HRA = 72,500 gross; deductions = 2,000 TDS + 80 cess + 200 PT + 500 welfare.
        $this->assertEquals(500, (float) $slip->components->firstWhere('code', 'WELFARE')->amount);
        $this->assertEquals(round((float) $slip->gross_salary - (float) $slip->total_deductions, 2), (float) $slip->net_pay);
        $this->assertGreaterThanOrEqual(2780, (float) $slip->total_deductions);
        $this->assertDatabaseHas('tds_liabilities', ['payslip_id' => $slip->id]);

        // Same month again → 422.
        $this->postJson("/api/payroll/employees/{$this->staff->id}/manual-payslip", ['period' => '2026-07', 'pin' => '2580'])->assertStatus(422);

        Sanctum::actingAs($this->staffUser);
        $this->assertSame(1, $this->getJson('/api/payroll/my-slips')->json('total'));
        $this->get("/api/payroll/payslips/{$slip->id}/pdf")->assertOk();

        // The month's regular run skips the employee who was paid manually.
        Sanctum::actingAs($this->admin);
        $regularId = $this->postJson('/api/payroll/runs', ['period' => '2026-07'])->assertStatus(202)->json('run.id');
        $this->assertFalse(Payslip::where('payroll_run_id', $regularId)->where('employee_id', $this->staff->id)->exists());
    }
}
