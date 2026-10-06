<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Employee loans, repaid as a monthly salary deduction (folded into each
 * payslip's `other_deductions` figure — see GeneratePayrollJob) rather than
 * a 4th deduction category. See LoanRepayment for the per-period trail.
 */
class LoanController extends Controller
{
    private const MANAGE_ROLES = User::PAYROLL_ACCESS_ROLES;
    private const VIEW_ROLES = User::PAYROLL_ACCESS_ROLES;

    public function index(Request $request)
    {
        if (! in_array($request->user()->role, self::VIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $loans = Loan::with(['employee:id,full_name,employee_code', 'givenBy:id,name', 'repayments'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($loans);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'principal_amount' => 'required|numeric|min:0.01',
            'monthly_installment' => 'required|numeric|min:0.01',
            'start_period' => 'required|date_format:Y-m',
            'notes' => 'nullable|string|max:1000',
        ]);

        $loan = Loan::create([
            'employee_id' => $validated['employee_id'],
            'principal_amount' => $validated['principal_amount'],
            'monthly_installment' => $validated['monthly_installment'],
            'start_period' => Carbon::createFromFormat('Y-m', $validated['start_period'])->startOfMonth(),
            'remaining_balance' => $validated['principal_amount'],
            'status' => Loan::STATUS_ACTIVE,
            'notes' => $validated['notes'] ?? null,
            'given_by_id' => $user->id,
        ]);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'create_loan',
            'subject_type' => 'Loan', 'subject_id' => $loan->id,
            'metadata' => ['employee_id' => $loan->employee_id, 'principal_amount' => (float) $loan->principal_amount],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($loan->load('employee:id,full_name,employee_code'), 201);
    }

    public function close(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $loan = Loan::findOrFail($id);
        if ($loan->status !== Loan::STATUS_ACTIVE) {
            return response()->json(['message' => 'Loan is already closed.'], 422);
        }

        $loan->update(['status' => Loan::STATUS_CLOSED]);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'close_loan',
            'subject_type' => 'Loan', 'subject_id' => $loan->id,
            'metadata' => ['remaining_balance_waived' => (float) $loan->remaining_balance],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($loan);
    }

    /** Employee self-service — their own loans and repayment history. */
    public function mine(Request $request)
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();
        if (! $employee) {
            return response()->json([]);
        }

        $loans = Loan::where('employee_id', $employee->id)->with('repayments')->orderByDesc('created_at')->get();

        return response()->json($loans);
    }

    /**
     * Employee self-service — request a loan. Created as STATUS_REQUESTED,
     * which GeneratePayrollJob's `where('status', Loan::STATUS_ACTIVE)` query
     * already ignores, so a request can never be deducted before approval.
     */
    public function request(Request $request)
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();
        if (! $employee) {
            return response()->json(['message' => 'No employee record linked to your account.'], 403);
        }

        $validated = $request->validate([
            'principal_amount' => 'required|numeric|min:0.01',
            'monthly_installment' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:1000',
        ]);

        $loan = Loan::create([
            'employee_id' => $employee->id,
            'principal_amount' => $validated['principal_amount'],
            'monthly_installment' => $validated['monthly_installment'],
            'start_period' => null,
            'remaining_balance' => $validated['principal_amount'],
            'status' => Loan::STATUS_REQUESTED,
            'notes' => $validated['reason'] ?? null,
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => 'request_loan',
            'subject_type' => 'Loan', 'subject_id' => $loan->id,
            'metadata' => ['employee_id' => $employee->id, 'principal_amount' => (float) $loan->principal_amount],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        Notifier::push(
            User::payrollAccessUserIds(),
            'loan_request',
            'New loan request',
            "{$employee->full_name} requested a loan of ₹" . number_format((float) $loan->principal_amount, 2),
            '/payroll-portal/loans',
            ['loan_id' => $loan->id, 'employee_id' => $employee->id],
        );

        return response()->json($loan, 201);
    }

    /** Payroll-access approval — turns a self-requested loan into an active one. */
    public function approveRequest(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $loan = Loan::findOrFail($id);
        if ($loan->status !== Loan::STATUS_REQUESTED) {
            return response()->json(['message' => 'This loan is not pending request.'], 422);
        }

        $validated = $request->validate([
            'monthly_installment' => 'nullable|numeric|min:0.01',
            'start_period' => 'required|date_format:Y-m',
        ]);

        $loan->update([
            'monthly_installment' => $validated['monthly_installment'] ?? $loan->monthly_installment,
            'start_period' => Carbon::createFromFormat('Y-m', $validated['start_period'])->startOfMonth(),
            'status' => Loan::STATUS_ACTIVE,
            'given_by_id' => $user->id,
        ]);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'approve_loan_request',
            'subject_type' => 'Loan', 'subject_id' => $loan->id,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        $loan->load('employee:id,full_name,user_id');
        if ($loan->employee?->user_id) {
            Notifier::push($loan->employee->user_id, 'loan_request', 'Loan request approved',
                "Your loan request for ₹" . number_format((float) $loan->principal_amount, 2) . ' was approved.',
                '/hrms/payroll');
        }

        return response()->json($loan);
    }

    /** Payroll-access rejection of a self-requested loan. */
    public function rejectRequest(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $loan = Loan::findOrFail($id);
        if ($loan->status !== Loan::STATUS_REQUESTED) {
            return response()->json(['message' => 'This loan is not pending request.'], 422);
        }

        $loan->update(['status' => Loan::STATUS_REJECTED, 'given_by_id' => $user->id]);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'reject_loan_request',
            'subject_type' => 'Loan', 'subject_id' => $loan->id,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        $loan->load('employee:id,full_name,user_id');
        if ($loan->employee?->user_id) {
            Notifier::push($loan->employee->user_id, 'loan_request', 'Loan request declined',
                "Your loan request for ₹" . number_format((float) $loan->principal_amount, 2) . ' was declined.',
                '/hrms/payroll');
        }

        return response()->json($loan);
    }
}
