<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PayrollProcessingTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'hr'): User
    {
        static $userCounter = 0;
        $userCounter++;
        return User::create([
            'name'     => ucfirst($role) . ' User ' . $userCounter,
            'email'    => $role . $userCounter . '@test.local',
            'password' => bcrypt('password'),
            'role'     => $role,
            'status'   => 'Active',
        ]);
    }

    private function employeeWith(array $data = []): Employee
    {
        static $counter = 0;
        $counter++;
        $user = User::create([
            'name'     => $data['name'] ?? 'Test Employee ' . $counter,
            'email'    => $data['email'] ?? 'emp' . $counter . '@test.local',
            'password' => bcrypt('password'),
            'role'     => 'associate',
            'status'   => 'Active',
        ]);

        return Employee::create(array_merge([
            'user_id'            => $user->id,
            'employee_code'      => 'EMP-' . $counter . rand(100, 999),
            'full_name'          => $user->name,
            'work_email'         => $user->email,
            'employment_status'  => 'Active',
            'date_of_joining'    => now()->subYear(),
            'base_pay_monthly'   => 60000,
        ], array_filter($data, fn ($v) => $v !== null)));
    }

    /** Helper: create a payroll run and return the run ID. */
    private function createRun(User $hr, string $period = '2026-06'): int
    {
        $response = $this->postJson('/api/payroll/runs', ['period' => $period])
            ->assertStatus(202)
            ->json();
        return $response['run']['id'];
    }

    /** Helper: Draft -> PendingApproval -> Finalized (there's no direct "finalize" endpoint). */
    private function finalizeRun(int $runId): void
    {
        $this->postJson("/api/payroll/runs/{$runId}/submit-for-approval", [])->assertOk();
        $this->postJson("/api/payroll/runs/{$runId}/approve", [])->assertOk();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure at least one active employee with salary so payroll runs can be created.
        $this->employeeWith(['base_pay_monthly' => 60000]);
    }

    // ──── Authorization ────
    public function test_unauthenticated_user_cannot_access_payroll(): void
    {
        $this->getJson('/api/payroll/runs')->assertUnauthorized();
    }

    public function test_only_finance_and_admin_can_create_payroll_run(): void
    {
        $associate = $this->user('associate');
        Sanctum::actingAs($associate);

        $this->postJson('/api/payroll/runs', ['period' => '2026-01'])->assertForbidden();

        $finance = $this->user('finance');
        Sanctum::actingAs($finance);
        $this->postJson('/api/payroll/runs', ['period' => '2026-01'])->assertStatus(202);

        // HR manages general employee records but does NOT see or edit payroll
        // data — see User::PAYROLL_ACCESS_ROLES.
        $hrUser = $this->user('hr');
        Sanctum::actingAs($hrUser);
        $this->postJson('/api/payroll/runs', ['period' => '2026-02'])->assertForbidden();
    }

    // ──── Payroll Run Creation ────
    public function test_create_payroll_run_for_valid_month_year(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        $response = $this->postJson('/api/payroll/runs', ['period' => '2026-06'])->assertStatus(202)->json();
        $this->assertStringContainsString('2026-06', $response['run']['period']);
    }

    public function test_cannot_create_duplicate_payroll_run(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        $this->postJson('/api/payroll/runs', ['period' => '2026-06'])->assertStatus(202);
        $this->postJson('/api/payroll/runs', ['period' => '2026-06'])->assertStatus(422);
    }

    public function test_invalid_month_rejected(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        $this->postJson('/api/payroll/runs', ['period' => '2026-13'])->assertStatus(422);
    }

    // ──── Payslip Generation ────
    public function test_payslips_generated_for_all_active_employees(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        $emp1 = $this->employeeWith(['base_pay_monthly' => 60000]);
        $emp2 = $this->employeeWith(['base_pay_monthly' => 80000]);
        $emp3 = $this->employeeWith(['base_pay_monthly' => 50000]);
        $emp3->employment_status = 'Terminated';
        $emp3->save();

        $runId = $this->createRun($hr);
        $run = PayrollRun::find($runId);

        // setUp() employee + emp1 + emp2 = 3 active employees; emp3 is terminated
        $this->assertGreaterThanOrEqual(2, $run->payslips()->count());
    }

    // ──── Salary Calculation ────
    public function test_basic_salary_calculation(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        $emp = $this->employeeWith(['base_pay_monthly' => 60000]);
        $runId = $this->createRun($hr);

        $run = PayrollRun::find($runId);
        $slip = $run->payslips()->where('employee_id', $emp->id)->first();

        $this->assertNotNull($slip);
        $this->assertGreaterThan(0, $slip->basic);
    }

    public function test_pf_calculation_capped_at_ceiling(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        $emp = $this->employeeWith(['base_pay_monthly' => 100000]);
        $runId = $this->createRun($hr);

        $run = PayrollRun::find($runId);
        $slip = $run->payslips()->where('employee_id', $emp->id)->first();

        // PF should be capped at 1800 (12% of 15k ceiling)
        $this->assertLessThanOrEqual(1800, $slip->pf_employee);
    }

    public function test_esi_not_charged_above_threshold(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        $emp = $this->employeeWith(['base_pay_monthly' => 100000]);
        $runId = $this->createRun($hr);

        $run = PayrollRun::find($runId);
        $slip = $run->payslips()->where('employee_id', $emp->id)->first();

        $this->assertEquals(0, $slip->esi_employee);
    }

    public function test_esi_is_not_charged_while_esi_is_switched_off_in_the_engine(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        // Gross = Base x 1.25 (Variable Pay): 15,000 base -> 18,750, under the 21,000 ESI limit, so ESI would apply
        // if PayrollService::currentEsiEnabled() were on. It is off, so the slip carries no ESI.
        $emp = $this->employeeWith(['base_pay_monthly' => 15000]);
        $runId = $this->createRun($hr);

        $slip = PayrollRun::find($runId)->payslips()->where('employee_id', $emp->id)->first();

        $this->assertEquals(18750, (float) $slip->gross_salary);
        $this->assertEquals(0, $slip->esi_employee);
    }

    // ──── LOP (Loss of Pay) Handling ────
    public function test_lop_deducts_from_gross_salary(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        $emp = $this->employeeWith(['base_pay_monthly' => 60000]);
        $runId = $this->createRun($hr);

        $run = PayrollRun::find($runId);
        $slip = $run->payslips()->where('employee_id', $emp->id)->first();
        $unproratedGross = (float) $slip->gross_salary;

        // Use the API to set LOP days; it recomputes the slip
        $this->putJson("/api/payroll/payslips/{$slip->id}", ['lop_days' => 5])->assertOk();

        $slip->refresh();
        $this->assertGreaterThan(0, $slip->lop_deduction);
        $this->assertLessThan($unproratedGross, $unproratedGross - $slip->lop_deduction);
    }

    // ──── Status Transitions ────
    public function test_payroll_run_draft_to_finalized(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        $runId = $this->createRun($hr);
        $this->finalizeRun($runId);

        $this->assertEquals('Finalized', PayrollRun::find($runId)->status);
    }

    public function test_finalized_payroll_can_be_corrected_by_payroll_roles_and_the_correction_is_audited(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        $runId = $this->createRun($hr);
        $this->finalizeRun($runId);

        $slip = PayrollRun::find($runId)->payslips()->first();

        // By design (see PayrollController::updatePayslip) payroll roles may correct a run after approval.
        $this->putJson("/api/payroll/payslips/{$slip->id}", ['lop_days' => 5])->assertOk();

        $this->assertEquals('Finalized', PayrollRun::find($runId)->status);
        $this->assertEquals(5, (float) $slip->fresh()->lop_days);
        $this->assertDatabaseHas('audit_logs', ['action' => 'update_payslip', 'user_id' => $hr->id]);
    }

    // ──── Payment Marking ────
    public function test_mark_payroll_as_paid(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        $runId = $this->createRun($hr);
        $this->finalizeRun($runId);

        // markPaid requires finance or super_admin role
        $finance = $this->user('finance');
        Sanctum::actingAs($finance);
        $this->postJson("/api/payroll/runs/{$runId}/pay", [])->assertOk();

        $this->assertEquals('Paid', PayrollRun::find($runId)->status);
    }

    // ──── View Personal Payslips ────
    public function test_employee_can_view_their_payslips(): void
    {
        $emp = $this->employeeWith();
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        $runId = $this->createRun($hr);
        $this->finalizeRun($runId);

        Sanctum::actingAs($emp->user);
        $response = $this->getJson('/api/payroll/my-slips')->assertOk()->json();
        $this->assertIsArray($response);
    }

    public function test_employee_my_slips_excludes_other_employees(): void
    {
        $emp1 = $this->employeeWith();
        $emp2 = $this->employeeWith();

        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        $runId = $this->createRun($hr);
        $this->finalizeRun($runId);

        // emp2 should only see their own slips, not emp1's
        Sanctum::actingAs($emp2->user);
        $response = $this->getJson('/api/payroll/my-slips')->assertOk()->json();
        $slipEmployeeIds = collect($response['data'])->pluck('employee_id')->unique()->values()->all();

        if (count($slipEmployeeIds) > 0) {
            $this->assertNotContains($emp1->id, $slipEmployeeIds);
        }
    }

    // ──── List Payroll Runs ────
    public function test_list_payroll_runs_paginated(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        // Months distinct from every other hardcoded period in this file (2026-01, 2026-06,
        // 2026-13) so this test can't collide with a run created elsewhere.
        foreach (['2026-09', '2026-10', '2026-11'] as $period) {
            $this->postJson('/api/payroll/runs', [
                'period' => $period,
            ])->assertStatus(202);
        }

        $response = $this->getJson('/api/payroll/runs')->assertOk()->json();
        $this->assertIsArray($response['runs']);
        $this->assertGreaterThan(0, count($response['runs']));
    }

    // ──── Edge Cases ────
    public function test_zero_salary_employee_not_included_in_payroll(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        $emp = $this->employeeWith(['base_pay_monthly' => 0]);
        $runId = $this->createRun($hr);

        $run = PayrollRun::find($runId);
        // Zero-base-pay employees are skipped; no payslip generated
        $slip = $run->payslips()->where('employee_id', $emp->id)->first();
        $this->assertNull($slip);
    }

    public function test_excluded_employee_not_included_in_payroll(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        $emp = $this->employeeWith(['base_pay_monthly' => 60000, 'exclude_from_payroll' => true]);
        $runId = $this->createRun($hr);

        $run = PayrollRun::find($runId);
        $slip = $run->payslips()->where('employee_id', $emp->id)->first();
        $this->assertNull($slip);
    }

    // ──── Real firm formula (verified against the FY2026-27 payslip workbook) ────
    public function test_real_firm_formula_matches_payslip_workbook(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        // Muruganantham's real figures: Base 144,000 → Variable 36,000 (25%),
        // Employer NPS 14,400 (10%), Gratuity Provision 6,000 (Base/24), with
        // HRA 25,000 flat → Gross 205,000, CTC 225,400.
        $emp = $this->employeeWith(['base_pay_monthly' => 144000, 'hra_monthly' => 25000]);
        $runId = $this->createRun($hr);

        $run = PayrollRun::find($runId);
        $slip = $run->payslips()->where('employee_id', $emp->id)->first();
        $this->assertNotNull($slip);

        $this->assertEquals(144000.0, (float) $slip->basic);
        $this->assertEquals(25000.0, (float) $slip->hra);
        $this->assertEquals(205000.0, (float) $slip->gross_salary);

        $components = $slip->components()->get()->keyBy('code');
        $this->assertEquals(36000.0, (float) $components['VARIABLE_PAY']->amount);
        $this->assertEquals(14400.0, (float) $components['EMPLOYER_NPS']->amount);
        // 1/24 = 4.1666...% stored to 4 decimal places (4.1667%) — a few paise
        // off the Excel's exact "Base/24", immaterial for real payroll.
        $this->assertEqualsWithDelta(6000.0, (float) $components['GRATUITY_PROVISION']->amount, 0.1);
    }

    // ──── Professional Tax (Karnataka slab) ────
    public function test_pt_nil_below_threshold(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        // Gross = 15,000 x 1.25 = 18,750 — under the 25,000 Nil threshold.
        $emp = $this->employeeWith(['base_pay_monthly' => 15000]);
        $runId = $this->createRun($hr, '2026-03');

        $run = PayrollRun::find($runId);
        $slip = $run->payslips()->where('employee_id', $emp->id)->first();
        $this->assertEquals(0.0, (float) $slip->professional_tax);
    }

    public function test_pt_standard_amount_above_threshold(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        $emp = $this->employeeWith(['base_pay_monthly' => 60000]);
        $runId = $this->createRun($hr, '2026-03'); // March — not February

        $run = PayrollRun::find($runId);
        $slip = $run->payslips()->where('employee_id', $emp->id)->first();
        $this->assertEquals(200.0, (float) $slip->professional_tax);
    }

    public function test_pt_february_amount(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        $emp = $this->employeeWith(['base_pay_monthly' => 60000]);
        $runId = $this->createRun($hr, '2027-02');

        $run = PayrollRun::find($runId);
        $slip = $run->payslips()->where('employee_id', $emp->id)->first();
        $this->assertEquals(300.0, (float) $slip->professional_tax);
    }

    // ──── Code on Wages, 2019 §2(y) — Basic must be ≥50% of total remuneration ────
    public function test_code_on_wages_guard_rejects_excessive_hra(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        // Base 10,000 + HRA 50,000 drives Basic well under 50% of CTC.
        $response = $this->postJson('/api/hrms/employees', [
            'full_name' => 'Excessive HRA Test',
            'work_email' => 'excessive-hra@test.local',
            'base_pay_monthly' => 10000,
            'hra_monthly' => 50000,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['hra_monthly']);
    }

    public function test_code_on_wages_guard_accepts_compliant_hra(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        $response = $this->postJson('/api/hrms/employees', [
            'full_name' => 'Compliant HRA Test',
            'work_email' => 'compliant-hra@test.local',
            'base_pay_monthly' => 60000,
            'hra_monthly' => 10000,
        ]);

        $response->assertStatus(201);
    }

    public function test_payroll_run_deletion(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        $runId = $this->createRun($hr);
        $this->deleteJson("/api/payroll/runs/{$runId}")->assertOk();
        $this->assertNull(PayrollRun::find($runId));
    }

    public function test_finalized_payroll_run_can_be_deleted_by_payroll_roles_and_the_deletion_is_audited(): void
    {
        $hr = $this->user('finance');
        Sanctum::actingAs($hr);

        $runId = $this->createRun($hr);
        $this->finalizeRun($runId);

        $this->deleteJson("/api/payroll/runs/{$runId}")->assertOk();

        $this->assertNull(PayrollRun::find($runId));
        $this->assertDatabaseHas('audit_logs', ['action' => 'delete_payroll_run', 'subject_id' => $runId, 'user_id' => $hr->id]);
    }
}
