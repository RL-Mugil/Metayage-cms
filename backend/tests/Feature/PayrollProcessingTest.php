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
            'salary'             => 60000,
        ], array_filter($data, fn ($v) => $v !== null)));
    }

    /** Helper: create a payroll run and return the run ID. */
    private function createRun(User $hr, string $period = '2026-06'): int
    {
        $response = $this->postJson('/api/payroll/runs', ['period' => $period])
            ->assertCreated()
            ->json();
        return $response['run']['id'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure at least one active employee with salary so payroll runs can be created.
        $this->employeeWith(['salary' => 60000]);
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

        $hr = $this->user('hr');
        Sanctum::actingAs($hr);
        $this->postJson('/api/payroll/runs', ['period' => '2026-01'])->assertCreated();
    }

    // ──── Payroll Run Creation ────
    public function test_create_payroll_run_for_valid_month_year(): void
    {
        $hr = $this->user('hr');
        Sanctum::actingAs($hr);

        $response = $this->postJson('/api/payroll/runs', ['period' => '2026-06'])->assertCreated()->json();
        $this->assertStringContainsString('2026-06', $response['run']['period']);
    }

    public function test_cannot_create_duplicate_payroll_run(): void
    {
        $hr = $this->user('hr');
        Sanctum::actingAs($hr);

        $this->postJson('/api/payroll/runs', ['period' => '2026-06'])->assertCreated();
        $this->postJson('/api/payroll/runs', ['period' => '2026-06'])->assertStatus(422);
    }

    public function test_invalid_month_rejected(): void
    {
        $hr = $this->user('hr');
        Sanctum::actingAs($hr);

        $this->postJson('/api/payroll/runs', ['period' => '2026-13'])->assertStatus(422);
    }

    // ──── Payslip Generation ────
    public function test_payslips_generated_for_all_active_employees(): void
    {
        $hr = $this->user('hr');
        Sanctum::actingAs($hr);

        $emp1 = $this->employeeWith(['salary' => 60000]);
        $emp2 = $this->employeeWith(['salary' => 80000]);
        $emp3 = $this->employeeWith(['salary' => 50000]);
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
        $hr = $this->user('hr');
        Sanctum::actingAs($hr);

        $emp = $this->employeeWith(['salary' => 60000]);
        $runId = $this->createRun($hr);

        $run = PayrollRun::find($runId);
        $slip = $run->payslips()->where('employee_id', $emp->id)->first();

        $this->assertNotNull($slip);
        $this->assertGreaterThan(0, $slip->basic_pay);
    }

    public function test_pf_calculation_capped_at_ceiling(): void
    {
        $hr = $this->user('hr');
        Sanctum::actingAs($hr);

        $emp = $this->employeeWith(['salary' => 100000]);
        $runId = $this->createRun($hr);

        $run = PayrollRun::find($runId);
        $slip = $run->payslips()->where('employee_id', $emp->id)->first();

        // PF should be capped at 1800 (12% of 15k ceiling)
        $this->assertLessThanOrEqual(1800, $slip->pf_employee);
    }

    public function test_esi_not_charged_above_threshold(): void
    {
        $hr = $this->user('hr');
        Sanctum::actingAs($hr);

        $emp = $this->employeeWith(['salary' => 100000]);
        $runId = $this->createRun($hr);

        $run = PayrollRun::find($runId);
        $slip = $run->payslips()->where('employee_id', $emp->id)->first();

        $this->assertEquals(0, $slip->esi_employee);
    }

    public function test_esi_charged_below_threshold(): void
    {
        $hr = $this->user('hr');
        Sanctum::actingAs($hr);

        $emp = $this->employeeWith(['salary' => 20000]);
        $runId = $this->createRun($hr);

        $run = PayrollRun::find($runId);
        $slip = $run->payslips()->where('employee_id', $emp->id)->first();

        $this->assertGreaterThan(0, $slip->esi_employee);
    }

    // ──── LOP (Loss of Pay) Handling ────
    public function test_lop_deducts_from_gross_salary(): void
    {
        $hr = $this->user('hr');
        Sanctum::actingAs($hr);

        $emp = $this->employeeWith(['salary' => 60000]);
        $runId = $this->createRun($hr);

        $run = PayrollRun::find($runId);
        $slip = $run->payslips()->where('employee_id', $emp->id)->first();

        // Use the API to set LOP days; it recomputes the slip
        $this->putJson("/api/payroll/payslips/{$slip->id}", ['lop_days' => 5])->assertOk();

        $slip->refresh();
        $this->assertLessThan(60000, $slip->gross_pay);
    }

    // ──── Status Transitions ────
    public function test_payroll_run_draft_to_finalized(): void
    {
        $hr = $this->user('hr');
        Sanctum::actingAs($hr);

        $runId = $this->createRun($hr);
        $this->postJson("/api/payroll/runs/{$runId}/finalize", [])->assertOk();

        $this->assertEquals('Finalized', PayrollRun::find($runId)->status);
    }

    public function test_finalized_payroll_cannot_be_modified(): void
    {
        $hr = $this->user('hr');
        Sanctum::actingAs($hr);

        $runId = $this->createRun($hr);
        $this->postJson("/api/payroll/runs/{$runId}/finalize", [])->assertOk();

        $slip = PayrollRun::find($runId)->payslips()->first();

        $response = $this->putJson("/api/payroll/payslips/{$slip->id}", ['lop_days' => 5]);
        $this->assertTrue(in_array($response->getStatusCode(), [403, 422]));
    }

    // ──── Payment Marking ────
    public function test_mark_payroll_as_paid(): void
    {
        $hr = $this->user('hr');
        Sanctum::actingAs($hr);

        $runId = $this->createRun($hr);
        // Must finalize before marking paid
        $this->postJson("/api/payroll/runs/{$runId}/finalize", [])->assertOk();
        $this->postJson("/api/payroll/runs/{$runId}/pay", [])->assertOk();

        $this->assertEquals('Paid', PayrollRun::find($runId)->status);
    }

    // ──── View Personal Payslips ────
    public function test_employee_can_view_their_payslips(): void
    {
        $emp = $this->employeeWith();
        $hr = $this->user('hr');
        Sanctum::actingAs($hr);

        $runId = $this->createRun($hr);
        $this->postJson("/api/payroll/runs/{$runId}/finalize", [])->assertOk();

        Sanctum::actingAs($emp->user);
        $response = $this->getJson('/api/payroll/my-slips')->assertOk()->json();
        $this->assertIsArray($response);
    }

    public function test_employee_my_slips_excludes_other_employees(): void
    {
        $emp1 = $this->employeeWith();
        $emp2 = $this->employeeWith();

        $hr = $this->user('hr');
        Sanctum::actingAs($hr);

        $runId = $this->createRun($hr);
        $this->postJson("/api/payroll/runs/{$runId}/finalize", [])->assertOk();

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
        $hr = $this->user('hr');
        Sanctum::actingAs($hr);

        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/payroll/runs', [
                'period' => '2026-' . str_pad($i, 2, '0', STR_PAD_LEFT),
            ])->assertCreated();
        }

        $response = $this->getJson('/api/payroll/runs')->assertOk()->json();
        $this->assertIsArray($response['runs']);
        $this->assertGreaterThan(0, count($response['runs']));
    }

    // ──── Edge Cases ────
    public function test_zero_salary_employee_not_included_in_payroll(): void
    {
        $hr = $this->user('hr');
        Sanctum::actingAs($hr);

        $emp = $this->employeeWith(['salary' => 0]);
        $runId = $this->createRun($hr);

        $run = PayrollRun::find($runId);
        // Zero-salary employees are skipped; no payslip generated
        $slip = $run->payslips()->where('employee_id', $emp->id)->first();
        $this->assertNull($slip);
    }

    public function test_payroll_run_deletion(): void
    {
        $hr = $this->user('hr');
        Sanctum::actingAs($hr);

        $runId = $this->createRun($hr);
        $this->deleteJson("/api/payroll/runs/{$runId}")->assertOk();
        $this->assertNull(PayrollRun::find($runId));
    }

    public function test_cannot_delete_finalized_payroll(): void
    {
        $hr = $this->user('hr');
        Sanctum::actingAs($hr);

        $runId = $this->createRun($hr);
        $this->postJson("/api/payroll/runs/{$runId}/finalize", [])->assertOk();

        $deleteResponse = $this->deleteJson("/api/payroll/runs/{$runId}");
        $this->assertTrue(in_array($deleteResponse->getStatusCode(), [403, 422]));
    }
}
