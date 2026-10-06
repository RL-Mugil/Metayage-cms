<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\SalaryRevision;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Formal "pay raise" workflow — own status/approved_by_id columns (same
 * pattern as InvestmentDeclaration). HRMSController::updateEmployee()'s
 * direct-edit path is unchanged and stays for minor corrections; this is
 * the one place a salary change gets a history record and goes through
 * approval, via ApprovalController::resolve() with type=SalaryRevision.
 * Salary figures — director/accountant/system admin only, see
 * User::PAYROLL_ACCESS_ROLES.
 */
class SalaryRevisionController extends Controller
{
    private const PROPOSE_ROLES = User::PAYROLL_ACCESS_ROLES;
    private const REVIEW_ROLES = User::PAYROLL_ACCESS_ROLES;

    public function store(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, self::PROPOSE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'new_base_pay' => 'required|numeric|min:0.01',
            // Omit to leave HRA unchanged — see SalaryRevision::new_hra.
            'new_hra' => 'nullable|numeric|min:0',
            'effective_period' => 'required|date_format:Y-m',
            'reason' => 'nullable|string|max:1000',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        // Code on Wages, 2019 §2(y) guard — same check as a direct employee
        // edit (StoreEmployeeRequest/UpdateEmployeeRequest), validated against
        // the resulting HRA (the proposed new_hra, or the employee's current
        // one if this revision doesn't touch it).
        $resultingHra = array_key_exists('new_hra', $validated) && $validated['new_hra'] !== null
            ? (float) $validated['new_hra']
            : (float) $employee->hra_monthly;
        $percent = app(PayrollService::class)->basicWagePercent((float) $validated['new_base_pay'], $resultingHra);
        if ($percent < 50.0) {
            return response()->json([
                'message' => "Basic Pay must be at least 50% of total remuneration under the Code on Wages, 2019 (currently would be {$percent}%). Reduce HRA or increase Base Pay.",
            ], 422);
        }

        $revision = SalaryRevision::create([
            'employee_id' => $employee->id,
            'previous_base_pay' => (float) $employee->base_pay_monthly,
            'new_base_pay' => $validated['new_base_pay'],
            'new_hra' => $validated['new_hra'] ?? null,
            'effective_period' => Carbon::createFromFormat('Y-m', $validated['effective_period'])->startOfMonth(),
            'reason' => $validated['reason'] ?? null,
            'requested_by_id' => $user->id,
            'status' => SalaryRevision::STATUS_PENDING,
        ]);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'propose_salary_revision',
            'subject_type' => 'SalaryRevision', 'subject_id' => $revision->id,
            'metadata' => ['employee_id' => $employee->id, 'previous_base_pay' => (float) $revision->previous_base_pay, 'new_base_pay' => (float) $revision->new_base_pay, 'new_hra' => $revision->new_hra !== null ? (float) $revision->new_hra : null],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($revision->load('employee:id,full_name,employee_code'), 201);
    }

    public function index(Request $request)
    {
        if (! in_array($request->user()->role, self::REVIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(
            SalaryRevision::with(['employee:id,full_name,employee_code', 'requestedBy:id,name', 'approvedBy:id,name'])
                ->orderByDesc('created_at')
                ->get()
        );
    }

    public function pending(Request $request)
    {
        if (! in_array($request->user()->role, self::REVIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $revisions = SalaryRevision::where('status', SalaryRevision::STATUS_PENDING)
            ->with(['employee:id,full_name,employee_code', 'requestedBy:id,name'])
            ->orderBy('created_at')
            ->get();

        return response()->json($revisions);
    }
}
