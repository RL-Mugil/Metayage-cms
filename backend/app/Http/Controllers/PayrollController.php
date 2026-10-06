<?php

namespace App\Http\Controllers;

use App\Http\PaginationHelper;
use App\Jobs\GeneratePayrollJob;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\InternalDocumentSignature;
use App\Models\LopAdjustment;
use App\Models\PayrollRun;
use App\Models\PayrollHour;
use App\Models\LoanRepayment;
use App\Models\TdsLiability;
use App\Models\Payslip;
use App\Models\PayslipComponent;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\PayrollService;
use App\Services\InternalDocumentSignatureService;
use App\Services\TdsLiabilityService;
use App\Support\Notifier;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use ZipArchive;

class PayrollController extends Controller
{
    // Salary/payroll data — director (partner), accountant (finance), and
    // system admin only. See User::PAYROLL_ACCESS_ROLES.
    private const MANAGE_ROLES = User::PAYROLL_ACCESS_ROLES; // create, edit, finalize
    private const VIEW_ROLES   = User::PAYROLL_ACCESS_ROLES; // see all runs
    private const PAY_ROLES    = User::PAYROLL_ACCESS_ROLES; // mark paid

    public function __construct(private PayrollService $payroll)
    {
    }

    /* ── Runs ──────────────────────────────────────────────────────────── */

    public function index(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, self::VIEW_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $runs = PayrollRun::with('processedBy:id,name')->orderByDesc('period')->get();

        $year = date('Y');
        $ytdPaid = PayrollRun::where('status', 'Paid')
            ->whereYear('period', $year)
            ->sum('net_total');

        return response()->json([
            'runs' => $runs,
            'ytd_paid' => (float) $ytdPaid,
            'can_manage' => in_array($user->role, self::MANAGE_ROLES),
            'can_pay' => in_array($user->role, self::PAY_ROLES),
        ]);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, self::VIEW_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $run = PayrollRun::with(['payslips' => fn ($q) => $q->orderBy('employee_name')->with('components')])->findOrFail($id);
        return response()->json($run);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'period' => 'required|date_format:Y-m', // e.g. "2026-06"
            'run_type' => 'nullable|in:Regular,OffCycle',
        ]);

        $period = Carbon::createFromFormat('Y-m', $validated['period'])->startOfMonth();
        $runType = $validated['run_type'] ?? 'Regular';

        // Only a Regular run is one-per-month; Off-Cycle runs are explicitly
        // allowed to coexist with a Regular run in the same period (Zoho allows
        // off-cycle runs anytime).
        if ($runType === 'Regular' && PayrollRun::where('period', $period->toDateString())->where('run_type', 'Regular')->exists()) {
            return response()->json(['message' => 'A regular payroll run for this month already exists.'], 422);
        }

        // Quick feasibility check: any active employees?
        // Salary is encrypted — can't cast in SQL; just check employment status.
        if (! Employee::where('employment_status', 'Active')->exists()) {
            return response()->json([
                'message' => 'No active employees found. Add employees in HRMS → Employees first.',
            ], 422);
        }

        $daysInMonth = $period->daysInMonth;

        $run = PayrollRun::create([
            'period'           => $period->toDateString(),
            'status'           => 'Processing',
            'run_type'         => $runType,
            'processed_by_id'  => $user->id,
        ]);

        GeneratePayrollJob::dispatch($run->id, $daysInMonth);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'create_payroll_run',
            'subject_type' => 'PayrollRun', 'subject_id' => $run->id,
            'metadata' => ['period' => $period->format('Y-m')],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['run' => $run, 'message' => 'Payroll generation queued.'], 202);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $run = PayrollRun::findOrFail($id);
        DB::transaction(function () use ($request, $user, $run, $id) {
            // Reimbursement claims swept into this run's payslips become
            // reclaimable by whichever run picks them up next.
            ExpenseClaim::whereIn('payslip_id', $run->payslips()->pluck('id'))->update(['payslip_id' => null]);

            // Return deducted loan installments to the loan balance before the
            // payslips are cascaded. TDS liabilities/allocations cascade with the
            // run; the audit log records that this was an intentional reversal.
            $repayments = LoanRepayment::whereIn('payslip_id', $run->payslips()->pluck('id'))->get();
            foreach ($repayments as $repayment) {
                $loan = $repayment->loan()->lockForUpdate()->first();
                if ($loan) {
                    $loan->remaining_balance = round((float) $loan->remaining_balance + (float) $repayment->installment_amount, 2);
                    $loan->status = \App\Models\Loan::STATUS_ACTIVE;
                    $loan->save();
                }
            }
            LoanRepayment::whereIn('payslip_id', $run->payslips()->pluck('id'))->delete();

            $run->delete();

            AuditLog::create([
                'user_id' => $user->id, 'action' => 'delete_payroll_run',
                'subject_type' => 'PayrollRun', 'subject_id' => (int) $id,
                'metadata' => ['period' => (string) $run->period, 'status_before_delete' => $run->status],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
        });

        return response()->json(['ok' => true]);
    }

    /* ── Lifecycle: Draft → PendingApproval → Finalized → Paid ───────────
       (or PendingApproval → Rejected → recall → Draft). No approver
       segregation — any PAYROLL_ACCESS_ROLES user may submit and approve. */

    public function submitForApproval(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $run = DB::transaction(function () use ($id, $user) {
            $run = PayrollRun::lockForUpdate()->findOrFail($id);
            if ($run->status !== 'Draft') {
                abort(422, 'Only draft runs can be submitted for approval.');
            }
            $this->refreshTotals($run);
            $run->update([
                'status' => 'PendingApproval',
                'submitted_by_id' => $user->id,
                'submitted_at' => now(),
            ]);
            return $run;
        });

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'submit_payroll_run_for_approval',
            'subject_type' => 'PayrollRun', 'subject_id' => $run->id,
            'metadata' => ['net_total' => $run->net_total],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        Notifier::push(
            User::payrollAccessUserIds(),
            'payroll_approval',
            'Pay run awaiting approval',
            'Pay run for ' . Carbon::parse($run->period)->format('F Y') . ' (₹' . number_format((float) $run->net_total, 2) . ') was submitted for approval.',
            '/payroll-portal/pay-runs',
            ['payroll_run_id' => $run->id],
        );

        return response()->json($run);
    }

    public function approve(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $run = DB::transaction(function () use ($id, $user) {
            $run = PayrollRun::lockForUpdate()->findOrFail($id);
            if ($run->status !== 'PendingApproval') {
                abort(422, 'Only runs pending approval can be approved.');
            }
            $run->update([
                'status' => 'Finalized',
                'finalized_at' => now(),
                'approved_by_id' => $user->id,
                'approved_at' => now(),
            ]);
            return $run;
        });

        app(TdsLiabilityService::class)->generateForRun($run);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'approve_payroll_run',
            'subject_type' => 'PayrollRun', 'subject_id' => $run->id,
            'metadata' => ['net_total' => $run->net_total],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        if ($run->submitted_by_id) {
            Notifier::push($run->submitted_by_id, 'payroll_approval', 'Pay run approved',
                'Pay run for ' . Carbon::parse($run->period)->format('F Y') . ' was approved and finalized.',
                '/payroll-portal/pay-runs');
        }

        return response()->json($run);
    }

    public function reject(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate(['reason' => 'required|string|max:1000']);

        $run = DB::transaction(function () use ($id, $validated) {
            $run = PayrollRun::lockForUpdate()->findOrFail($id);
            if ($run->status !== 'PendingApproval') {
                abort(422, 'Only runs pending approval can be rejected.');
            }
            $run->update(['status' => 'Rejected', 'rejection_reason' => $validated['reason']]);
            return $run;
        });

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'reject_payroll_run',
            'subject_type' => 'PayrollRun', 'subject_id' => $run->id,
            'metadata' => ['reason' => $validated['reason']],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        if ($run->submitted_by_id) {
            Notifier::push($run->submitted_by_id, 'payroll_approval', 'Pay run rejected',
                'Pay run for ' . Carbon::parse($run->period)->format('F Y') . ": {$validated['reason']}",
                '/payroll-portal/pay-runs');
        }

        return response()->json($run);
    }

    public function recall(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $run = DB::transaction(function () use ($id) {
            $run = PayrollRun::lockForUpdate()->findOrFail($id);
            if ($run->status !== 'Rejected') {
                abort(422, 'Only rejected runs can be recalled.');
            }
            $run->update(['status' => 'Draft', 'rejection_reason' => null]);
            return $run;
        });

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'recall_payroll_run',
            'subject_type' => 'PayrollRun', 'subject_id' => $run->id,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($run);
    }

    /** Pays a chosen subset of a Finalized run's payslips. Run auto-completes to
     *  Paid once every non-withheld payslip has been paid. */
    public function payslipsBulkPay(Request $request, $runId)
    {
        $user = $request->user();
        if (! in_array($user->role, self::PAY_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'payslip_ids' => 'required|array|min:1',
            'payslip_ids.*' => 'integer',
        ]);

        $run = DB::transaction(function () use ($runId, $validated) {
            $run = PayrollRun::lockForUpdate()->findOrFail($runId);
            if ($run->status !== 'Finalized') {
                abort(422, 'Only finalized runs can be paid.');
            }

            Payslip::where('payroll_run_id', $run->id)
                ->whereIn('id', $validated['payslip_ids'])
                ->where('withheld', false)
                ->whereNull('paid_at')
                ->update(['paid_at' => now()]);

            $stillUnpaid = Payslip::where('payroll_run_id', $run->id)
                ->where('withheld', false)
                ->whereNull('paid_at')
                ->exists();

            if (! $stillUnpaid) {
                $run->update(['status' => 'Paid', 'paid_at' => now()]);
            }

            return $run->fresh();
        });

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'pay_payslips',
            'subject_type' => 'PayrollRun', 'subject_id' => $run->id,
            'metadata' => ['payslip_ids' => $validated['payslip_ids'], 'run_status' => $run->status],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($run);
    }

    /** Convenience wrapper — pays every still-unpaid, non-withheld payslip in the run at once. */
    public function markPaid(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, self::PAY_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $run = PayrollRun::findOrFail($id);
        if ($run->status !== 'Finalized') {
            return response()->json(['message' => 'Only finalized runs can be marked paid.'], 422);
        }

        $payableIds = Payslip::where('payroll_run_id', $run->id)
            ->where('withheld', false)
            ->whereNull('paid_at')
            ->pluck('id')
            ->all();

        if (empty($payableIds)) {
            return response()->json(['message' => 'There are no payable payslips in this run (all are withheld or already paid).'], 422);
        }

        $request->merge(['payslip_ids' => $payableIds]);

        return $this->payslipsBulkPay($request, $id);
    }

    /* ── Salary Withhold / Release / Revert (Draft-run only; withholding
       only gates net-pay disbursement — statutory deductions are untouched) ── */

    public function withholdPayslip(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate(['reason' => 'required|string|max:1000']);

        $slip = DB::transaction(function () use ($id, $validated) {
            $slip = Payslip::lockForUpdate()->findOrFail($id);
            $run = PayrollRun::findOrFail($slip->payroll_run_id);
            if ($run->status !== 'Draft') {
                abort(422, 'Salary can only be withheld while the run is in draft.');
            }
            $slip->update(['withheld' => true, 'withhold_reason' => $validated['reason']]);
            return $slip;
        });

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'withhold_salary',
            'subject_type' => 'Payslip', 'subject_id' => $slip->id,
            'metadata' => ['reason' => $validated['reason']],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($slip);
    }

    public function revertWithhold(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $slip = DB::transaction(function () use ($id) {
            $slip = Payslip::lockForUpdate()->findOrFail($id);
            $run = PayrollRun::findOrFail($slip->payroll_run_id);
            if ($run->status !== 'Draft') {
                abort(422, 'Withhold can only be reverted while the run is in draft.');
            }
            if (! $slip->withheld) {
                abort(422, 'This payslip is not withheld.');
            }
            $slip->update(['withheld' => false, 'withhold_reason' => null]);
            return $slip;
        });

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'revert_withhold',
            'subject_type' => 'Payslip', 'subject_id' => $slip->id,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($slip);
    }

    /** Folds every outstanding withheld-salary total for one employee into a chosen draft run. */
    public function releaseWithheld(Request $request, $employeeId)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate(['payroll_run_id' => 'required|exists:payroll_runs,id']);

        $targetSlip = DB::transaction(function () use ($employeeId, $validated) {
            $run = PayrollRun::lockForUpdate()->findOrFail($validated['payroll_run_id']);
            if ($run->status !== 'Draft') {
                abort(422, 'Withheld salary can only be released into a draft run.');
            }

            $targetSlip = Payslip::lockForUpdate()
                ->where('payroll_run_id', $run->id)
                ->where('employee_id', $employeeId)
                ->first();
            if (! $targetSlip) {
                abort(422, 'This employee has no payslip in the selected draft run.');
            }
            if ($targetSlip->withheld) {
                abort(422, "Cannot release while this employee's current-run salary is itself withheld.");
            }

            $withheldSlips = Payslip::where('employee_id', $employeeId)
                ->where('withheld', true)
                ->whereNull('released_in_run_id')
                ->get();

            if ($withheldSlips->isEmpty()) {
                abort(422, 'This employee has no outstanding withheld salary.');
            }

            $releaseTotal = round((float) $withheldSlips->sum('net_pay'), 2);

            $targetSlip->update([
                'withheld_release_amount' => round((float) $targetSlip->withheld_release_amount + $releaseTotal, 2),
                'net_pay' => round((float) $targetSlip->net_pay + $releaseTotal, 2),
            ]);

            Payslip::whereIn('id', $withheldSlips->pluck('id'))->update(['released_in_run_id' => $run->id]);

            $this->refreshTotals($run);

            return $targetSlip->fresh();
        });

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'release_withheld_salary',
            'subject_type' => 'Payslip', 'subject_id' => $targetSlip->id,
            'metadata' => ['employee_id' => (int) $employeeId, 'released_amount' => (float) $targetSlip->withheld_release_amount],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($targetSlip);
    }

    /* ── LOP Adjust (prior-month reversal/correction, ≤3 months back) ────── */

    public function createLopAdjustment(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'adjustment_type' => 'required|in:Reversal,Correction',
            'days' => 'required|numeric|min:0.5|max:31',
            'period' => 'required|date_format:Y-m',
            'reason' => 'nullable|string|max:1000',
        ]);

        $period = Carbon::createFromFormat('Y-m', $validated['period'])->startOfMonth();
        if ($period->lt(now()->startOfMonth()->subMonths(3))) {
            return response()->json(['message' => 'LOP can only be adjusted for the last 3 months.'], 422);
        }

        $targetRun = PayrollRun::where('period', $period->toDateString())
            ->whereIn('status', ['Finalized', 'Paid'])
            ->exists();
        if (! $targetRun) {
            return response()->json(['message' => 'No finalized/paid pay run exists for that period.'], 422);
        }

        $adjustment = LopAdjustment::create([
            'employee_id' => $validated['employee_id'],
            'adjustment_type' => $validated['adjustment_type'],
            'days' => $validated['days'],
            'period' => $period->toDateString(),
            'reason' => $validated['reason'] ?? null,
            'created_by_id' => $user->id,
            'status' => LopAdjustment::STATUS_PENDING,
        ]);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'create_lop_adjustment',
            'subject_type' => 'LopAdjustment', 'subject_id' => $adjustment->id,
            'metadata' => $validated,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($adjustment, 201);
    }

    public function lopAdjustments(Request $request)
    {
        if (! in_array($request->user()->role, self::VIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(
            LopAdjustment::with('employee:id,full_name,employee_code')->orderByDesc('created_at')->get()
        );
    }

    /* ── Downloads ─────────────────────────────────────────────────────── */

    /** PDF bank advice for a run — withheld salaries excluded. */
    public function bankAdvicePreview(Request $request, $id, InternalDocumentSignatureService $signatures)
    {
        if (! in_array($request->user()->role, self::VIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $run = PayrollRun::with(['payslips' => fn ($q) => $q->where('withheld', false)->orderBy('employee_name'), 'payslips.employee'])
            ->findOrFail($id);

        return response()->json([
            'period' => Carbon::parse($run->period)->format('F Y'),
            'rows' => $run->payslips->map(fn ($slip) => $this->bankAdviceRow($run, $slip, $signatures))->values(),
        ]);
    }

    public function signBankAdvice(Request $request, $id, InternalDocumentSignatureService $signatures)
    {
        $user = $request->user();
        if (! in_array($user->role, self::VIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'pin' => 'nullable|digits:4',
            'verified' => 'required|boolean',
        ]);
        $run = PayrollRun::with(['payslips' => fn ($q) => $q->where('withheld', false), 'payslips.employee'])->findOrFail($id);
        $verified = (bool) $validated['verified'];
        $signingEmployee = Employee::where('user_id', $user->id)->first();
        if (! $signingEmployee) {
            return response()->json(['message' => 'Your user account is not linked to an employee signature PIN.'], 422);
        }
        if ($verified && ! $signingEmployee->verifyInternalSignaturePin((string) ($validated['pin'] ?? ''))) {
            return response()->json(['message' => 'The document PIN is incorrect.'], 422);
        }

        foreach ($run->payslips as $slip) {
            $signatures->sign(
                'bank_advice_employee',
                (int) $slip->id,
                $this->bankAdviceEmployeeFingerprint($run, $slip, $signatures),
                $user,
                $verified
            );
        }

        $rows = '';
        $allVerified = true;
        foreach ($run->payslips as $slip) {
            $row = $this->bankAdviceRow($run, $slip, $signatures);
            $allVerified = $allVerified && $row['verified'];
            $hours = $row['hours_logged_minutes'] === null ? '—' : sprintf('%d:%02d', intdiv((int) $row['hours_logged_minutes'], 60), (int) $row['hours_logged_minutes'] % 60);
            $status = $row['verified'] ? '<span style="color:#159447;font-weight:bold">&#10003; Signature valid</span>' : '<span style="color:#c62828;font-weight:bold">&#10005; Not verified</span>';
            $rows .= '<tr><td>'.e($row['employee_name']).'</td><td>'.e($row['employee_code']).'</td><td>'.e($row['bank_name'] ?? '').'</td><td>'.e($row['account_number'] ?? '').'</td><td>'.e($row['ifsc'] ?? '').'</td><td style="text-align:right">'.e($hours).'</td><td style="text-align:right">₹'.number_format($row['net_pay'], 2).'</td><td style="text-align:right">₹'.number_format($row['reimbursement'], 2).'</td><td style="text-align:right">₹'.number_format($row['total'], 2).'</td><td>'.$status.'</td></tr>';
        }

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'sign_bank_advice_preview',
            'subject_type' => 'PayrollRun', 'subject_id' => $run->id,
            'metadata' => ['verified' => $verified, 'document_scope' => 'entire_bank_advice'],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['message' => 'Bank Advice verification saved.']);
    }

    private function bankAdviceRow(PayrollRun $run, Payslip $slip, InternalDocumentSignatureService $signatures): array
    {
        $employee = $slip->employee;
        $signature = InternalDocumentSignature::where('document_type', 'bank_advice_employee')
            ->where('document_id', $slip->id)->first();
        $verified = $signatures->verify($signature, $this->bankAdviceEmployeeFingerprint($run, $slip, $signatures));

        return [
            'payslip_id' => $slip->id, 'employee_id' => $employee->id,
            'employee_name' => $slip->employee_name, 'employee_code' => $slip->employee_code,
            'bank_name' => $employee->bank_name, 'account_number' => $employee->bank_account_number,
            'ifsc' => $employee->bank_ifsc_code, 'hours_logged_minutes' => $slip->hours_logged_minutes,
            'net_pay' => (float) $slip->net_pay, 'reimbursement' => (float) $slip->reimbursements,
            'total' => round((float) $slip->net_pay + (float) $slip->reimbursements, 2),
            'verified' => $verified,
        ];
    }

    private function bankAdviceEmployeeFingerprint(PayrollRun $run, Payslip $slip, InternalDocumentSignatureService $signatures): string
    {
        return $signatures->fingerprint('bank_advice_employee', (int) $slip->id, [
            'run_period' => optional($run->period)->toDateString(),
            'slip_updated_at' => optional($slip->updated_at)->toIso8601String(),
            'net_pay' => (string) $slip->net_pay,
            'reimbursements' => (string) $slip->reimbursements,
            'hours_logged_minutes' => $slip->hours_logged_minutes,
        ]);
    }

    public function downloadBankAdvice(Request $request, $id, InternalDocumentSignatureService $signatures)
    {
        $user = $request->user();
        if (! in_array($user->role, self::VIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $run = PayrollRun::with(['payslips' => fn ($q) => $q->where('withheld', false)->orderBy('employee_name'), 'payslips.employee'])->findOrFail($id);

        $employeeSignatureCount = InternalDocumentSignature::where('document_type', 'bank_advice_employee')
            ->whereIn('document_id', $run->payslips->pluck('id'))
            ->count();
        if ($employeeSignatureCount !== $run->payslips->count()) {
            return response()->json(['message' => 'Open the Bank Advice preview and confirm every employee row before downloading.'], 422);
        }

        $signatureFacts = $run->payslips->map(fn ($slip) => [
            'id' => $slip->id,
            'updated_at' => optional($slip->updated_at)->toIso8601String(),
            'employee_code' => $slip->employee_code,
            'amount' => number_format((float) $slip->net_pay + (float) $slip->reimbursements, 2, '.', ''),
        ])->values()->all();
        $signature = $signatures->sign(
            'bank_advice',
            (int) $run->id,
            $signatures->fingerprint('bank_advice', (int) $run->id, [
                'run_updated_at' => optional($run->updated_at)->toIso8601String(),
                'period' => optional($run->period)->toDateString(),
                'rows' => $signatureFacts,
            ]),
            $user
        )->load('signedBy:id,name,email,role');

        $rows = '';
        foreach ($run->payslips as $slip) {
            $bank = Employee::where('id', $slip->employee_id)->first(['bank_account_number', 'bank_ifsc_code']);
            $rows .= '<tr><td>'.e($slip->employee_name).'</td><td>'.e($slip->employee_code).'</td><td>'.e($bank?->bank_account_number ?? '').'</td><td>'.e($bank?->bank_ifsc_code ?? '').'</td><td style="text-align:right">₹'.number_format((float) $slip->net_pay + (float) $slip->reimbursements, 2).'</td></tr>';
        }

        $rows = '';
        $allVerified = true;
        foreach ($run->payslips as $slip) {
            $row = $this->bankAdviceRow($run, $slip, $signatures);
            $allVerified = $allVerified && $row['verified'];
            $hours = $row['hours_logged_minutes'] === null ? '—' : sprintf('%d:%02d', intdiv((int) $row['hours_logged_minutes'], 60), (int) $row['hours_logged_minutes'] % 60);
            $status = $row['verified'] ? '<span style="color:#159447;font-weight:bold">&#10003; Signature valid</span>' : '<span style="color:#c62828;font-weight:bold">&#10005; Not verified</span>';
            $rows .= '<tr><td>'.e($row['employee_name']).'</td><td>'.e($row['employee_code']).'</td><td>'.e($row['bank_name'] ?? '').'</td><td>'.e($row['account_number'] ?? '').'</td><td>'.e($row['ifsc'] ?? '').'</td><td style="text-align:right">'.e($hours).'</td><td style="text-align:right">₹'.number_format($row['net_pay'], 2).'</td><td style="text-align:right">₹'.number_format($row['reimbursement'], 2).'</td><td style="text-align:right">₹'.number_format($row['total'], 2).'</td><td>'.$status.'</td></tr>';
        }

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'download_bank_advice',
            'subject_type' => 'PayrollRun', 'subject_id' => $run->id,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        $signaturePanel = '<div style="position:relative;margin-top:22px;margin-left:auto;width:275px;height:62px;border:1px dotted #777;padding:5px 56px 4px 7px;color:#222;background:#fff;text-align:left;font-family:DejaVu Sans,sans-serif"><div style="font-size:15px;line-height:17px">Signature valid</div><div style="font-size:12px;line-height:16px;white-space:nowrap">Digitally signed by <strong>'.e($signature->signedBy->name).'</strong></div><div style="font-size:10px;line-height:13px">Date: '.e($signature->signed_at?->format('d/m/Y h:i A')).' IST</div><div style="position:absolute;right:5px;top:1px;font-size:57px;line-height:58px;color:#159447;font-family:DejaVu Sans,sans-serif;transform:rotate(-8deg)">&#10003;</div></div>';
        $html = '<html><head><style>body{font-family:DejaVu Sans,sans-serif;color:#17233c}table{width:100%;border-collapse:collapse}th,td{padding:7px;border:1px solid #d9dee8;font-size:10px}h1{font-size:18px}</style></head><body><h1>Bank Advice — '.e(Carbon::parse($run->period)->format('F Y')).'</h1><table><thead><tr><th>Employee</th><th>Code</th><th>Account Number</th><th>IFSC</th><th>Amount</th></tr></thead><tbody>'.$rows.'</tbody></table>'.$signaturePanel.'</body></html>';
        $check = $allVerified ? '&#10003;' : '&#10005;';
        $statusLabel = $allVerified ? 'Signature valid' : 'Signature not verified';
        $statusColor = $allVerified ? '#159447' : '#c62828';
        $signaturePanel = '<div style="position:relative;margin-top:22px;margin-left:auto;width:275px;height:62px;border:1px dotted #777;padding:5px 56px 4px 7px;color:#222;background:#fff;text-align:left;font-family:DejaVu Sans,sans-serif"><div style="font-size:15px;line-height:17px">'.$statusLabel.'</div><div style="font-size:12px;line-height:16px;white-space:nowrap">Digitally signed by <strong>'.e($signature->signedBy->name).'</strong></div><div style="font-size:10px;line-height:13px">Date: '.e($signature->signed_at?->timezone('Asia/Kolkata')->format('d/m/Y h:i A')).' IST</div><div style="position:absolute;right:5px;top:1px;font-size:57px;line-height:58px;color:'.$statusColor.';font-family:DejaVu Sans,sans-serif;transform:rotate(-8deg)">'.$check.'</div></div>';
        $html = '<html><head><style>body{font-family:DejaVu Sans,sans-serif;color:#17233c}table{width:100%;border-collapse:collapse}th,td{padding:6px;border:1px solid #d9dee8;font-size:8px}h1{font-size:18px}</style></head><body><h1>Bank Advice — '.e(Carbon::parse($run->period)->format('F Y')).'</h1><table><thead><tr><th>Employee</th><th>Code</th><th>Bank Name</th><th>Account Number</th><th>IFSC</th><th>Hrs Logged</th><th>Net Pay</th><th>Reimbursement</th><th>Total Paid</th><th>Verification</th></tr></thead><tbody>'.$rows.'</tbody></table>'.$signaturePanel.'</body></html>';
        return Pdf::loadHTML($html)->setPaper('a4', 'landscape')->download('bank-advice-'.Carbon::parse($run->period)->format('Y-m').'.pdf');
    }

    /** Zipped per-employee monthly payslip PDFs — Zoho's "Download All TDS Worksheets". */
    public function downloadTdsWorksheets(Request $request, $id, PayslipPdfController $pdfController)
    {
        $user = $request->user();
        if (! in_array($user->role, self::VIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $run = PayrollRun::with(['payslips.components', 'payslips.employee.designation', 'payslips.internalSignature.signedBy'])->findOrFail($id);
        if (! in_array($run->status, ['Finalized', 'Paid'], true)) {
            return response()->json(['message' => 'TDS worksheets are only available once a run is finalized.'], 422);
        }

        foreach ($run->payslips as $slip) {
            $fingerprint = $pdfController->monthlyFingerprint($slip, app(InternalDocumentSignatureService::class));
            if (! app(InternalDocumentSignatureService::class)->verify($slip->internalSignature, $fingerprint)) {
                return response()->json(['message' => "Payslip {$slip->employee_code} is awaiting Accounts internal signature."], 422);
            }
        }

        $zipPath = storage_path('app/tmp/tds-worksheets-' . uniqid() . '.zip');
        @mkdir(dirname($zipPath), 0755, true);

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($run->payslips as $slip) {
            $zip->addFromString("payslip-{$slip->employee_code}.pdf", $pdfController->rawMonthlyPdfOutput($slip));
        }
        $zip->close();

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'download_tds_worksheets',
            'subject_type' => 'PayrollRun', 'subject_id' => $run->id,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->download($zipPath, 'tds-worksheets-' . Carbon::parse($run->period)->format('Y-m') . '.zip')->deleteFileAfterSend(true);
    }

    /* ── Payslip adjustments (draft only) ──────────────────────────────── */

    public function updatePayslip(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'lop_days' => 'nullable|numeric|min:0|max:31',
            'tds' => 'nullable|numeric|min:0',
            'health_insurance' => 'nullable|numeric|min:0',
            'other_deductions' => 'nullable|numeric|min:0',
            'reimbursements' => 'nullable|numeric|min:0',
            'hours_logged' => ['nullable', 'regex:/^\d{1,4}:[0-5]\d$/'],
        ]);

        $slip = DB::transaction(function () use ($request, $user, $id, $validated) {
            $slip = Payslip::lockForUpdate()->findOrFail($id);
            $run = PayrollRun::lockForUpdate()->findOrFail($slip->payroll_run_id);
            // Payroll roles may correct a run after approval/payment. Every
            // mutation remains auditable and the linked TDS liability is synced.

            $periodDate = Carbon::parse($run->period);
            $daysInMonth = $periodDate->daysInMonth;
            $employee = Employee::findOrFail($slip->employee_id);
            $amounts = $this->payroll->computeSlip(
                $employee,
                (float) ($validated['lop_days'] ?? $slip->lop_days),
                (float) ($validated['tds'] ?? $slip->tds),
                $daysInMonth,
                (int) $periodDate->format('n'),
                SalaryComponent::activeOrdered(),
                (bool) $employee->professional_tax_exempt,
                (float) ($validated['health_insurance'] ?? $slip->health_insurance),
                (float) ($validated['other_deductions'] ?? $slip->other_deductions)
            );

            // Reimbursements were already locked in at generation time — an LOP/TDS
            // edit only touches the gross-based numbers, never re-sums claims.
            // gross_salary (the unprorated monthly figure) is locked in from
            // generation time too — an LOP/TDS edit never re-derives it.
            $reimbursements = (float) $slip->reimbursements;
            if (array_key_exists('reimbursements', $validated)) {
                $reimbursements = round((float) $validated['reimbursements'], 2);
            }
            if (array_key_exists('hours_logged', $validated)) {
                [$hours, $minutes] = array_map('intval', explode(':', $validated['hours_logged']));
                $totalMinutes = ($hours * 60) + $minutes;
                PayrollHour::updateOrCreate(
                    ['employee_id' => $slip->employee_id, 'period' => $run->period->toDateString()],
                    ['minutes' => $totalMinutes, 'entered_by_id' => $user->id]
                );
                $slip->hours_logged_minutes = $totalMinutes;
            }
            $componentRows = $amounts['components'];
            unset($amounts['components'], $amounts['ctc_addon_total'], $amounts['total_ctc'], $amounts['monthly_gross'], $amounts['payable_gross']);
            $annualAdjustment = 0.0;
            $annualShortage = 0;
            if ((int) $periodDate->format('n') === 4) {
                $priorYear = $periodDate->copy()->subYear();
                $priorPeriods = collect(range(0, 11))->map(fn ($i) => $priorYear->copy()->addMonths($i)->toDateString());
                $priorHours = PayrollHour::where('employee_id', $slip->employee_id)->whereIn('period', $priorPeriods)->pluck('minutes');
                if ($priorHours->count() === 12) {
                    $annualShortage = max(0, (181 * 60 * 12) - (int) $priorHours->sum());
                    if ($annualShortage > 0) {
                        $basis = collect($amounts['components'])->whereIn('code', ['BASIC', 'VARIABLE_PAY', 'HRA'])->sum('amount');
                        $annualAdjustment = round(($basis / (181 * 60)) * $annualShortage, 2);
                    }
                }
            }
            $amounts['total_deductions'] = round((float) $amounts['total_deductions'] + $annualAdjustment, 2);
            $amounts['net_pay'] = max(0.0, round((float) $amounts['net_pay'] - $annualAdjustment, 2));
            $amounts['annual_hours_shortage_minutes'] = $annualShortage;
            $amounts['annual_hours_adjustment'] = $annualAdjustment;
            $amounts['reimbursements'] = $reimbursements;
            $amounts['hours_logged_minutes'] = $slip->hours_logged_minutes;

            $slip->update($amounts);

            if ($liability = TdsLiability::where('payslip_id', $slip->id)->first()) {
                $liability->update(['tds_deducted' => $slip->tds, 'taxable_amount' => round((float) $slip->gross_salary - (float) $slip->lop_deduction, 2)]);
            }

            $slip->components()->delete();
            if (! empty($componentRows)) {
                $now = now();
                PayslipComponent::insert(array_map(
                    fn ($c) => [
                        'payslip_id' => $slip->id,
                        'code' => $c['code'],
                        'name' => $c['name'],
                        'calc_type' => $c['calc_type'],
                        'category' => $c['category'],
                        'value' => $c['value'],
                        'percentage_of' => $c['percentage_of'],
                        'sequence' => $c['sequence'],
                        'amount' => $c['amount'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $componentRows
                ));
            }

            $this->refreshTotals($run);

            AuditLog::create([
                'user_id' => $user->id, 'action' => 'update_payslip',
                'subject_type' => 'Payslip', 'subject_id' => $slip->id,
                'metadata' => [
                    'lop_days' => $amounts['lop_days'], 'tds' => $amounts['tds'],
                    'health_insurance' => $amounts['health_insurance'], 'other_deductions' => $amounts['other_deductions'],
                ],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);

            return $slip->fresh('components');
        });

        return response()->json($slip);
    }

    /* ── Employee self-service ─────────────────────────────────────────── */

    public function mySlips(Request $request)
    {
        $user = $request->user();
        if ($user->isClientRole()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $employee = Employee::where('user_id', $user->id)->first();
        if (! $employee) return response()->json(['data' => [], 'total' => 0, 'per_page' => 25, 'current_page' => 1, 'last_page' => 1, 'has_more' => false]);

        // Drafts are HR work-in-progress; employees only see finalized/paid slips.
        $query = Payslip::with('run:id,period,status', 'components')
            ->where('employee_id', $employee->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['Finalized', 'Paid']))
            ->orderByDesc('id');

        return response()->json(PaginationHelper::paginate($query, $request));
    }

    /** HR view of one employee's payslips — the Payroll Portal employee detail page. */
    public function employeeSlips(Request $request, $employeeId)
    {
        $user = $request->user();
        if (! in_array($user->role, self::VIEW_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate(['fiscal_year' => 'nullable|integer|min:2000|max:2100']);
        $fy = (int) ($validated['fiscal_year'] ?? (now()->month >= 4 ? now()->year : now()->year - 1));
        $fyStart = \Illuminate\Support\Carbon::create($fy, 4, 1)->toDateString();
        $fyEnd = \Illuminate\Support\Carbon::create($fy + 1, 3, 31)->toDateString();

        $slips = Payslip::with('run:id,period,status')
            ->where('employee_id', $employeeId)
            ->whereHas('run', fn ($q) => $q->whereBetween('period', [$fyStart, $fyEnd]))
            ->orderByDesc('id')
            ->get();

        return response()->json($slips);
    }

    /* ── Helpers ───────────────────────────────────────────────────────── */

    private function refreshTotals(PayrollRun $run): void
    {
        $agg = Payslip::where('payroll_run_id', $run->id)
            ->selectRaw('count(*) as cnt, coalesce(sum(gross_salary - lop_deduction),0) as gross, coalesce(sum(total_deductions),0) as ded, coalesce(sum(net_pay + reimbursements),0) as net')
            ->first();

        $run->update([
            'employee_count' => (int) $agg->cnt,
            'gross_total' => round((float) $agg->gross, 2),
            'deductions_total' => round((float) $agg->ded, 2),
            'net_total' => round((float) $agg->net, 2),
        ]);
    }
}
