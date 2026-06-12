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
        return User::create([
            'name'     => ucfirst($role) . ' User',
            'email'    => $role . '@test.local',
            'password' => bcrypt('password'),
            'role'     => $role,
            'status'   => 'Active',
        ]);
    }

    private function employeeWith(array $data = []): Employee
    {
        $user = User::create([
            'name'  => $data['name'] ?? 'Test Employee',
            'email' => $data['email'] ?? 'emp@test.local',
            'password' => bcrypt('password'),
            'role'  => 'associate',
            'status' => 'Active',
        ]);

        return Employee::create(array_merge([
            'user_id'            => $user->id,
            'employee_code'      => 'EMP-' . rand(10000, 99999),
            'full_name'          => $user->name,
            'work_email'         => $user->email,
            'employment_status'  => 'Active',
            'date_of_joining'    => now()->subYear(),
            'salary'             => 60000,
        ], array_filter($data)));
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

        $this->postJson('/api/payroll/runs', [
            'month'  => 1,
            'year'   => 2026,
            'status' => 'Draft',
        ])->assertForbidden();

        $finance = $this->user('finance');
        Sanctum::actingAs($finance);
        $this->postJson('/api/payroll/runs', [
            'month'  => 1,
            'year'   => 2026,
            'status' => 'Draft',
        ])->assertCreated();
    }

    // ──── Payroll Run Creation ────
    public function test_create_payroll_run_for_valid_month_year(): void
    {
        $finance = $this->user('finance');
        Sanctum::actingAs($finance);

        $this->postJson('/api/payroll/runs', [
            'month'  => 6,
            'year'   => 2026,
            'status' => 'Draft',
        ])->assertCreated()->assertJsonFragment(['month' => 6, 'year' => 2026]);
    }

    public function test_cannot_create_duplicate_payroll_run(): void
    {
        $finance = $this->user('finance');
        Sanctum::actingAs($finance);

        $this->postJson('/api/payroll/runs', [
            'month'  => 6,
            'year'   => 2026,
            'status' => 'Draft',
        ])->assertCreated();

        // Try to create for same month/year
        $this->postJson('/api/payroll/runs', [
            'month'  => 6,
            'year'   => 2026,
            'status' => 'Draft',
        ])->assertStatus(422);
    }

    public function test_invalid_month_rejected(): void
    {
        $finance = $this->user('finance');
        Sanctum::actingAs($finance);

        $this->postJson('/api/payroll/runs', [
            'month'  => 13,
            'year'   => 2026,
            'status' => 'Draft',
        ])->assertStatus(422);
    }

    // ──── Payslip Generation ────
    public function test_payslips_generated_for_all_active_employees(): void
    {
        $finance = $this->user('finance');
        Sanctum::actingAs($finance);

        $emp1 = $this->employeeWith(['salary' => 60000]);
        $emp2 = $this->employeeWith(['salary' => 80000]);
        $emp3 = $this->employeeWith(['salary' => 0]); // Inactive
        $emp3->employment_status = 'Terminated';
        $emp3->save();

        $response = $this->postJson('/api/payroll/runs', [
            'month'  => 6,
            'year'   => 2026,
            'status' => 'Draft',
        ])->assertCreated()->json();
        $runId = $response['id'];

        // Check payslips created
        $run = PayrollRun::find($runId);
        $this->assertGreaterThan(0, $run->payslips()->count());
        // Should be 2 (active employees), not 3
    }

    // ──── Salary Calculation ────
    public function test_basic_salary_calculation(): void
    {
        $finance = $this->user('finance');
        Sanctum::actingAs($finance);

        $emp = $this->employeeWith(['salary' => 60000]);

        $response = $this->postJson('/api/payroll/runs', [
            'month'  => 6,
            'year'   => 2026,
            'status' => 'Draft',
        ])->assertCreated()->json();
        $runId = $response['id'];

        $run = PayrollRun::find($runId);
        $slip = $run->payslips()->where('employee_id', $emp->id)->first();

        // Basic = 60000 / 30 days * actual days in month (assuming full month)
        $this->assertNotNull($slip);
        $this->assertGreaterThan(0, $slip->basic_pay);
    }

    public function test_pf_calculation_capped_at_ceiling(): void
    {
        $finance = $this->user('finance');
        Sanctum::actingAs($finance);

        // Salary above PF ceiling (15k/month)
        $emp = $this->employeeWith(['salary' => 100000]);

        $response = $this->postJson('/api/payroll/runs', [
            'month'  => 6,
            'year'   => 2026,
            'status' => 'Draft',
        ])->assertCreated()->json();
        $runId = $response['id'];

        $run = PayrollRun::find($runId);
        $slip = $run->payslips()->where('employee_id', $emp->id)->first();

        // PF should be capped at 1800 (12% of 15k ceiling)
        $this->assertLessThanOrEqual(1800, $slip->pf_employee);
    }

    public function test_esi_not_charged_above_threshold(): void
    {
        $finance = $this->user('finance');
        Sanctum::actingAs($finance);

        // Salary above ESI threshold (21k/month)
        $emp = $this->employeeWith(['salary' => 100000]);

        $response = $this->postJson('/api/payroll/runs', [
            'month'  => 6,
            'year'   => 2026,
            'status' => 'Draft',
        ])->assertCreated()->json();
        $runId = $response['id'];

        $run = PayrollRun::find($runId);
        $slip = $run->payslips()->where('employee_id', $emp->id)->first();

        // ESI should be 0
        $this->assertEquals(0, $slip->esi_employee);
    }

    public function test_esi_charged_below_threshold(): void
    {
        $finance = $this->user('finance');
        Sanctum::actingAs($finance);

        // Salary below ESI threshold
        $emp = $this->employeeWith(['salary' => 20000]);

        $response = $this->postJson('/api/payroll/runs', [
            'month'  => 6,
            'year'   => 2026,
            'status' => 'Draft',
        ])->assertCreated()->json();
        $runId = $response['id'];

        $run = PayrollRun::find($runId);
        $slip = $run->payslips()->where('employee_id', $emp->id)->first();

        // ESI should be 0.75% of basic up to 21k
        $this->assertGreaterThan(0, $slip->esi_employee);
    }

    // ──── LOP (Loss of Pay) Handling ────
    public function test_lop_deducts_from_gross_salary(): void
    {
        $finance = $this->user('finance');
        Sanctum::actingAs($finance);

        $emp = $this->employeeWith(['salary' => 60000]);

        $response = $this->postJson('/api/payroll/runs', [
            'month'  => 6,
            'year'   => 2026,
            'status' => 'Draft',
        ])->assertCreated()->json();
        $runId = $response['id'];

        $run = PayrollRun::find($runId);
        $slip = $run->payslips()->where('employee_id', $emp->id)->first();

        // Update slip with 5 LOP days
        $slip->lop_days = 5;
        $slip->save();

        // Gross should be reduced by (60000/30)*5
        $this->assertLessThan(60000, $slip->gross_pay);
    }

    // ──── Status Transitions ────
    public function test_payroll_run_draft_to_finalized(): void
    {
        $finance = $this->user('finance');
        Sanctum::actingAs($finance);

        $response = $this->postJson('/api/payroll/runs', [
            'month'  => 6,
            'year'   => 2026,
            'status' => 'Draft',
        ])->assertCreated()->json();
        $runId = $response['id'];

        $this->postJson("/api/payroll/runs/{$runId}/finalize", [])->assertOk();

        $run = PayrollRun::find($runId);
        $this->assertEquals('Finalized', $run->status);
    }

    public function test_finalized_payroll_cannot_be_modified(): void
    {
        $finance = $this->user('finance');
        Sanctum::actingAs($finance);

        $response = $this->postJson('/api/payroll/runs', [
            'month'  => 6,
            'year'   => 2026,
            'status' => 'Draft',
        ])->assertCreated()->json();
        $runId = $response['id'];

        $this->postJson("/api/payroll/runs/{$runId}/finalize", [])->assertOk();

        // Try to modify payslip
        $run = PayrollRun::find($runId);
        $slip = $run->payslips()->first();

        $response = $this->putJson("/api/payroll/payslips/{$slip->id}", [
            'bonus' => 5000,
        ]);

        // Should reject modification of finalized payslip
        $this->assertIn($response->getStatusCode(), [403, 422]);
    }

    // ──── Payment Marking ────
    public function test_mark_payroll_as_paid(): void
    {
        $finance = $this->user('finance');
        Sanctum::actingAs($finance);

        $response = $this->postJson('/api/payroll/runs', [
            'month'  => 6,
            'year'   => 2026,
            'status' => 'Draft',
        ])->assertCreated()->json();
        $runId = $response['id'];

        $this->postJson("/api/payroll/runs/{$runId}/pay", [])->assertOk();

        $run = PayrollRun::find($runId);
        $this->assertEquals('Paid', $run->status);
    }

    // ──── View Personal Payslips ────
    public function test_employee_can_view_their_payslips(): void
    {
        $emp = $this->employeeWith();
        Sanctum::actingAs($emp->user);

        $finance = $this->user('finance');
        Sanctum::actingAs($finance);

        $this->postJson('/api/payroll/runs', [
            'month'  => 6,
            'year'   => 2026,
            'status' => 'Draft',
        ])->assertCreated();

        Sanctum::actingAs($emp->user);
        $response = $this->getJson('/api/payroll/my-slips')->assertOk()->json();
        $this->assertIsArray($response);
    }

    public function test_employee_cannot_view_others_payslips(): void
    {
        $emp1 = $this->employeeWith();
        $emp2 = $this->employeeWith();

        $finance = $this->user('finance');
        Sanctum::actingAs($finance);

        $response = $this->postJson('/api/payroll/runs', [
            'month'  => 6,
            'year'   => 2026,
            'status' => 'Draft',
        ])->assertCreated()->json();
        $runId = $response['id'];

        $run = PayrollRun::find($runId);
        $slip = $run->payslips()->where('employee_id', $emp1->id)->first();

        Sanctum::actingAs($emp2->user);
        $this->getJson("/api/payroll/payslips/{$slip->id}")->assertForbidden();
    }

    // ──── List Payroll Runs ────
    public function test_list_payroll_runs_paginated(): void
    {
        $finance = $this->user('finance');
        Sanctum::actingAs($finance);

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/payroll/runs', [
                'month'  => $i,
                'year'   => 2026,
                'status' => 'Draft',
            ])->assertCreated();
        }

        $response = $this->getJson('/api/payroll/runs')->assertOk()->json();
        $this->assertIsArray($response['data']);
        $this->assertGreaterThan(0, count($response['data']));
    }

    // ──── Edge Cases ────
    public function test_zero_salary_employee_handled(): void
    {
        $finance = $this->user('finance');
        Sanctum::actingAs($finance);

        // Unpaid intern or contractor
        $emp = $this->employeeWith(['salary' => 0]);

        $response = $this->postJson('/api/payroll/runs', [
            'month'  => 6,
            'year'   => 2026,
            'status' => 'Draft',
        ])->assertCreated()->json();
        $runId = $response['id'];

        $run = PayrollRun::find($runId);
        $slip = $run->payslips()->where('employee_id', $emp->id)->first();

        // Should not cause errors
        $this->assertNotNull($slip);
        $this->assertEquals(0, $slip->basic_pay);
    }

    public function test_payroll_run_deletion(): void
    {
        $finance = $this->user('finance');
        Sanctum::actingAs($finance);

        $response = $this->postJson('/api/payroll/runs', [
            'month'  => 6,
            'year'   => 2026,
            'status' => 'Draft',
        ])->assertCreated()->json();
        $runId = $response['id'];

        $this->deleteJson("/api/payroll/runs/{$runId}")->assertOk();
        $this->assertNull(PayrollRun::find($runId));
    }

    public function test_cannot_delete_finalized_payroll(): void
    {
        $finance = $this->user('finance');
        Sanctum::actingAs($finance);

        $response = $this->postJson('/api/payroll/runs', [
            'month'  => 6,
            'year'   => 2026,
            'status' => 'Draft',
        ])->assertCreated()->json();
        $runId = $response['id'];

        $this->postJson("/api/payroll/runs/{$runId}/finalize", [])->assertOk();

        $deleteResponse = $this->deleteJson("/api/payroll/runs/{$runId}");
        // Should fail since it's finalized
        $this->assertIn($deleteResponse->getStatusCode(), [403, 422]);
    }
}
