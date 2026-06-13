<?php

namespace App\Http\Controllers;

use App\Http\PaginationHelper;
use App\Jobs\GeneratePayrollJob;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PayrollController extends Controller
{
    private const MANAGE_ROLES = ['super_admin', 'hr'];           // create, edit, finalize
    private const VIEW_ROLES   = ['super_admin', 'hr', 'finance', 'partner']; // see all runs
    private const PAY_ROLES    = ['super_admin', 'finance'];      // mark paid

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

        $run = PayrollRun::with(['payslips' => fn ($q) => $q->orderBy('employee_name')])->findOrFail($id);
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
        ]);

        $period = Carbon::createFromFormat('Y-m', $validated['period'])->startOfMonth();

        if (PayrollRun::where('period', $period->toDateString())->exists()) {
            return response()->json(['message' => 'A payroll run for this month already exists.'], 422);
        }

        // Quick feasibility check: are there any employees with salaries?
        $hasEligible = Employee::where('employment_status', 'Active')
            ->whereRaw('CAST(salary AS FLOAT) > 0')
            ->exists();

        if (! $hasEligible) {
            return response()->json([
                'message' => 'No active employees with a salary set. Add salaries in HRMS → Employees first.',
            ], 422);
        }

        $daysInMonth = $period->daysInMonth;

        $run = PayrollRun::create([
            'period'           => $period->toDateString(),
            'status'           => 'Processing',
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
        if ($run->status !== 'Draft') {
            return response()->json(['message' => 'Only draft runs can be deleted.'], 422);
        }

        $run->delete();

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'delete_payroll_run',
            'subject_type' => 'PayrollRun', 'subject_id' => (int) $id,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }

    /* ── Lifecycle ─────────────────────────────────────────────────────── */

    public function finalize(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $run = DB::transaction(function () use ($id) {
            $run = PayrollRun::lockForUpdate()->findOrFail($id);
            if ($run->status !== 'Draft') {
                abort(422, 'Run is not in draft state.');
            }
            $this->refreshTotals($run);
            $run->update(['status' => 'Finalized', 'finalized_at' => now()]);
            return $run;
        });

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'finalize_payroll_run',
            'subject_type' => 'PayrollRun', 'subject_id' => $run->id,
            'metadata' => ['net_total' => $run->net_total],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($run);
    }

    public function markPaid(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, self::PAY_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $run = DB::transaction(function () use ($id) {
            $run = PayrollRun::lockForUpdate()->findOrFail($id);
            if ($run->status !== 'Finalized') {
                abort(422, 'Only finalized runs can be marked paid.');
            }
            $run->update(['status' => 'Paid', 'paid_at' => now()]);
            return $run;
        });

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'pay_payroll_run',
            'subject_type' => 'PayrollRun', 'subject_id' => $run->id,
            'metadata' => ['net_total' => $run->net_total],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($run);
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
        ]);

        $slip = DB::transaction(function () use ($id, $validated) {
            $slip = Payslip::lockForUpdate()->findOrFail($id);
            $run = PayrollRun::lockForUpdate()->findOrFail($slip->payroll_run_id);
            if ($run->status !== 'Draft') {
                abort(422, 'Payslips are locked once the run is finalized.');
            }

            $daysInMonth = Carbon::parse($run->period)->daysInMonth;
            $amounts = $this->payroll->computeSlip(
                (float) $slip->gross_salary,
                (float) ($validated['lop_days'] ?? $slip->lop_days),
                (float) ($validated['tds'] ?? $slip->tds),
                $daysInMonth
            );
            $slip->update($amounts);
            $this->refreshTotals($run);

            return $slip->fresh();
        });

        return response()->json($slip);
    }

    /* ── Employee self-service ─────────────────────────────────────────── */

    public function mySlips(Request $request)
    {
        $user = $request->user();
        if ($user->role === 'client') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $employee = Employee::where('user_id', $user->id)->first();
        if (! $employee) return response()->json(['data' => [], 'total' => 0, 'per_page' => 25, 'current_page' => 1, 'last_page' => 1, 'has_more' => false]);

        // Drafts are HR work-in-progress; employees only see finalized/paid slips.
        $query = Payslip::with('run:id,period,status')
            ->where('employee_id', $employee->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['Finalized', 'Paid']))
            ->orderByDesc('id');

        return response()->json(PaginationHelper::paginate($query, $request));
    }

    /* ── Helpers ───────────────────────────────────────────────────────── */

    private function refreshTotals(PayrollRun $run): void
    {
        $agg = Payslip::where('payroll_run_id', $run->id)
            ->selectRaw('count(*) as cnt, coalesce(sum(gross_salary - lop_deduction),0) as gross, coalesce(sum(total_deductions),0) as ded, coalesce(sum(net_pay),0) as net')
            ->first();

        $run->update([
            'employee_count' => (int) $agg->cnt,
            'gross_total' => round((float) $agg->gross, 2),
            'deductions_total' => round((float) $agg->ded, 2),
            'net_total' => round((float) $agg->net, 2),
        ]);
    }
}
