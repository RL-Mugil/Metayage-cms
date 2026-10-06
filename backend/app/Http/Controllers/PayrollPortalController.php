<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Aggregation endpoints behind the Zoho-style Payroll Portal shell
 * (resources/js/layouts/PayrollPortalLayout.tsx, resources/js/pages/PayrollPortal/*).
 * Everything here reads data already owned by PayrollController/ApprovalController —
 * no new tables, just portal-shaped views over them.
 */
class PayrollPortalController extends Controller
{
    private const VIEW_ROLES = User::PAYROLL_ACCESS_ROLES;

    public function dashboard(Request $request)
    {
        if (! in_array($request->user()->role, self::VIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $latestRun = PayrollRun::orderByDesc('period')->first();
        $upcomingPeriod = $latestRun
            ? Carbon::parse($latestRun->period)->addMonthNoOverflow()->startOfMonth()
            : now()->startOfMonth();
        $upcomingExists = PayrollRun::where('period', $upcomingPeriod->toDateString())->exists();

        $ytdYear = now()->year;
        $ytd = Payslip::whereHas('run', fn ($q) => $q->whereYear('period', $ytdYear))
            ->selectRaw('coalesce(sum(gross_salary - lop_deduction),0) as gross, coalesce(sum(total_deductions),0) as deductions, coalesce(sum(net_pay),0) as net, coalesce(sum(tds),0) as tds')
            ->first();

        $costTrend = PayrollRun::orderByDesc('period')->limit(6)->get()
            ->sortBy('period')
            ->values()
            ->map(fn ($run) => [
                'period' => Carbon::parse($run->period)->format('M Y'),
                'gross' => (float) $run->gross_total,
                'deductions' => (float) $run->deductions_total,
                'net' => (float) $run->net_total,
            ]);

        return response()->json([
            'upcoming_run' => [
                'period' => $upcomingPeriod->format('Y-m'),
                'already_created' => $upcomingExists,
            ],
            'latest_run' => $latestRun,
            'employee_count' => Employee::where('employment_status', 'Active')->count(),
            'ytd' => [
                'gross' => (float) ($ytd->gross ?? 0),
                'deductions' => (float) ($ytd->deductions ?? 0),
                'net' => (float) ($ytd->net ?? 0),
                'tds' => (float) ($ytd->tds ?? 0),
            ],
            'cost_trend' => $costTrend,
        ]);
    }

    /** Pending reimbursement claims — the Payroll Portal's "Approvals → Reimbursements" list. */
    public function pendingReimbursements(Request $request)
    {
        if (! in_array($request->user()->role, self::VIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $claims = ExpenseClaim::whereNotNull('reimbursement_type_id')
            ->where('status', 'Pending')
            ->with(['employee:id,full_name,employee_code', 'reimbursementType:id,name'])
            ->orderBy('claim_date')
            ->get();

        return response()->json($claims);
    }
}
