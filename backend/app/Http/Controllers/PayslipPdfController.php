<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Downloadable payslip PDFs — monthly (one payslip) and yearly (a fiscal-year,
 * Apr–Mar, one column per month, matching the firm's real payslip format).
 * Separate from PayrollController to stay under the ~200-line guideline.
 */
class PayslipPdfController extends Controller
{
    private const VIEW_ROLES = User::PAYROLL_ACCESS_ROLES;

    private function letterhead(): array
    {
        $rows = DB::table('system_settings')
            ->whereIn('key', ['payroll_company_name', 'payroll_company_address', 'payroll_company_logo_url'])
            ->pluck('value', 'key');

        return [
            'company_name' => $rows['payroll_company_name'] ?? '',
            'company_address' => $rows['payroll_company_address'] ?? '',
            'company_logo_url' => $rows['payroll_company_logo_url'] ?? '',
        ];
    }

    /** True if $user may view any employee's payslip; false means "only their own". */
    private function canViewAny(Request $request): bool
    {
        return in_array($request->user()->role, self::VIEW_ROLES, true);
    }

    private function resolveOwnEmployee(Request $request): ?Employee
    {
        return Employee::where('user_id', $request->user()->id)->first();
    }

    public function monthly(Request $request, $payslipId)
    {
        $slip = Payslip::with(['components', 'employee.designation', 'run:id,period,status'])->findOrFail($payslipId);

        if (! $this->canViewAny($request)) {
            $own = $this->resolveOwnEmployee($request);
            if (! $own || (int) $slip->employee_id !== (int) $own->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            if (! in_array($slip->run->status, ['Finalized', 'Paid'], true)) {
                return response()->json(['message' => 'This payslip is not finalized yet.'], 403);
            }
        }

        return $this->renderMonthlyPdf($slip)->download("payslip-{$slip->employee_code}-{$slip->run->period->format('Y-m')}.pdf");
    }

    /** Employer-issued salary certificate using the latest finalized/paid salary structure. */
    public function salaryCertificate(Request $request, $employeeId)
    {
        if (! $this->canViewAny($request)) return response()->json(['message' => 'Forbidden'], 403);
        $slip = Payslip::with(['components', 'employee.designation', 'run:id,period,status'])
            ->where('employee_id', $employeeId)
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['Finalized', 'Paid']))
            ->latest('id')->firstOrFail();
        $earnings = $slip->components->where('category', 'earning')->sortBy('sequence');
        $addons = $slip->components->where('category', 'ctc_addon')->sortBy('sequence');
        $gross = round((float) $slip->gross_salary - (float) $slip->lop_deduction, 2);
        $ctc = round($gross + (float) $addons->sum('amount'), 2);
        $rows = $earnings->map(fn ($c) => '<tr><td>'.e($c->name).'</td><td style="text-align:right">₹'.number_format((float)$c->amount,2).'</td></tr>')->implode('');
        $addonRows = $addons->map(fn ($c) => '<tr><td>'.e($c->name).' (employer cost)</td><td style="text-align:right">₹'.number_format((float)$c->amount,2).'</td></tr>')->implode('');
        $html = '<html><head><style>body{font-family:DejaVu Sans,sans-serif;color:#17233c}h1{font-size:20px}table{width:100%;border-collapse:collapse;margin-top:16px}td{padding:7px;border-bottom:1px solid #ddd}.total{font-weight:bold}</style></head><body>'
            .'<h1>Salary Certificate</h1><p>This is to certify that <b>'.e($slip->employee->full_name).'</b> ('.e($slip->employee->employee_code).') is employed with the company as '.e($slip->employee->designation?->title ?? 'Employee').'.</p>'
            .'<p>Monthly salary structure effective for '.e($slip->run->period->format('F Y')).':</p><table>'.$rows.'<tr class="total"><td>Gross Salary</td><td style="text-align:right">₹'.number_format($gross,2).'</td></tr>'.$addonRows.'<tr class="total"><td>Monthly CTC</td><td style="text-align:right">₹'.number_format($ctc,2).'</td></tr></table><p style="margin-top:28px">Issued on '.now()->format('d M Y').'.</p></body></html>';
        return Pdf::loadHTML($html)->download('salary-certificate-'.$slip->employee_code.'.pdf');
    }

    /** Raw PDF bytes for one payslip — used by PayrollController's zipped "Download All TDS Worksheets". */
    public function rawMonthlyPdfOutput(Payslip $slip): string
    {
        $slip->loadMissing('components', 'employee.designation', 'run:id,period,status');

        return $this->renderMonthlyPdf($slip)->output();
    }

    private function renderMonthlyPdf(Payslip $slip)
    {
        $data = $this->payslipViewData($slip);

        return Pdf::loadView('payslips.monthly', $data)->setPaper('a4', 'portrait');
    }

    public function yearly(Request $request, $employeeId)
    {
        $validated = $request->validate([
            'fiscal_year' => 'nullable|integer|min:2000|max:2100',
        ]);
        // FY label `2026` means Apr 2026 – Mar 2027, matching the firm's existing
        // `fiscal_month: 'April'` convention (SettingsController::getSettings()).
        $fy = (int) ($validated['fiscal_year'] ?? (now()->month >= 4 ? now()->year : now()->year - 1));
        $start = Carbon::create($fy, 4, 1)->startOfDay();
        $end = Carbon::create($fy + 1, 3, 31)->endOfDay();
        $label = "FY {$fy}-" . substr((string) ($fy + 1), -2);

        return $this->renderSummary($request, $employeeId, $start, $end, $label, "payslip-yearly-{employee_code}-{$fy}.pdf");
    }

    /**
     * Flexible payslip summary — quarterly, half-yearly, or any custom date
     * range an employee (or payroll admin) chooses, reusing the same
     * row-based month-column PDF as the yearly download. `yearly()` above is
     * kept as a convenience wrapper around this for the common whole-FY case.
     */
    public function summary(Request $request, $employeeId)
    {
        $validated = $request->validate([
            'from' => 'required|date_format:Y-m',
            'to' => 'required|date_format:Y-m|after_or_equal:from',
        ]);
        $start = Carbon::createFromFormat('Y-m', $validated['from'])->startOfMonth();
        $end = Carbon::createFromFormat('Y-m', $validated['to'])->endOfMonth();

        if ($start->diffInMonths($end) > 11) {
            return response()->json(['message' => 'The date range cannot exceed 12 months.'], 422);
        }

        $label = $start->format('M Y') === $end->format('M Y')
            ? $start->format('M Y')
            : "{$start->format('M Y')} – {$end->format('M Y')}";

        return $this->renderSummary($request, $employeeId, $start, $end, $label, "payslip-summary-{employee_code}-{$validated['from']}-to-{$validated['to']}.pdf");
    }

    private function renderSummary(Request $request, $employeeId, Carbon $start, Carbon $end, string $periodLabel, string $filenameTemplate)
    {
        $employee = Employee::with('designation')->findOrFail($employeeId);

        if (! $this->canViewAny($request)) {
            $own = $this->resolveOwnEmployee($request);
            if (! $own || (int) $employee->id !== (int) $own->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $runs = PayrollRun::whereBetween('period', [$start->toDateString(), $end->toDateString()])
            ->with(['payslips' => fn ($q) => $q->where('employee_id', $employee->id)->with('components')])
            ->get()
            ->keyBy(fn ($run) => Carbon::parse($run->period)->format('Y-m'));

        // One column per month in range, even for months with no processed run
        // yet — the sample format shows those as "NA", not an omitted column.
        $months = [];
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addMonthNoOverflow()) {
            $run = $runs->get($cursor->format('Y-m'));
            $slip = $run?->payslips->first();
            $hoursMinutes = (int) Attendance::where('employee_id', $employee->id)
                ->whereYear('attendance_date', $cursor->year)
                ->whereMonth('attendance_date', $cursor->month)
                ->sum('duration_minutes');
            $months[] = [
                'label' => $cursor->format('M'),
                'period' => $cursor->copy(),
                'slip' => $slip,
                'hours_display' => $hoursMinutes > 0 ? sprintf('%d:%02d:00', intdiv($hoursMinutes, 60), $hoursMinutes % 60) : null,
            ];
        }

        $data = [
            'employee' => $employee,
            'periodLabel' => $periodLabel,
            'months' => $months,
            'earningRows' => $this->buildComponentRows($months, 'earning'),
            'ctcAddonRows' => $this->buildComponentRows($months, 'ctc_addon'),
            'deductionRows' => $this->buildDeductionRows($months),
            'totalCtcRow' => $this->buildScalarRow($months, fn ($slip) => (float) $slip->gross_salary - (float) $slip->lop_deduction
                + $slip->components->where('category', 'ctc_addon')->sum('amount')),
            'totalDeductionsRow' => $this->buildScalarRow($months, fn ($slip) => (float) $slip->total_deductions),
            'netPayRow' => $this->buildScalarRow($months, fn ($slip) => (float) $slip->net_pay - (float) $slip->reimbursements),
            'reimbursementsRow' => $this->buildScalarRow($months, fn ($slip) => (float) $slip->reimbursements),
            'creditedRow' => $this->buildScalarRow($months, fn ($slip) => (float) $slip->net_pay),
            'letterhead' => $this->letterhead(),
            'generatedAt' => now(),
        ];

        $pdf = Pdf::loadView('payslips.yearly', $data)->setPaper('a4', 'landscape');

        return $pdf->download(str_replace('{employee_code}', $employee->employee_code, $filenameTemplate));
    }

    /**
     * One row per distinct component code seen across any month (in first-seen
     * sequence order), each with a 12-value array (null where that month has no
     * slip or didn't carry that component) plus a fiscal-year total.
     */
    private function buildComponentRows(array $months, string $category): array
    {
        $order = []; // code => ['name' => ..., 'sequence' => ...]
        foreach ($months as $m) {
            if (! $m['slip']) {
                continue;
            }
            foreach ($m['slip']->components->where('category', $category) as $c) {
                $order[$c->code] ??= ['name' => $c->name, 'sequence' => $c->sequence];
            }
        }
        uasort($order, fn ($a, $b) => $a['sequence'] <=> $b['sequence']);

        $rows = [];
        foreach ($order as $code => $meta) {
            $values = [];
            $total = 0.0;
            foreach ($months as $m) {
                $amount = $m['slip']?->components->firstWhere('code', $code)?->amount;
                $values[] = $amount !== null ? (float) $amount : null;
                $total += (float) ($amount ?? 0);
            }
            $rows[] = ['label' => $meta['name'], 'values' => $values, 'total' => $total];
        }

        return $rows;
    }

    /** Fixed deduction rows — only included if at least one month has a nonzero value. */
    private function buildDeductionRows(array $months): array
    {
        $candidates = [
            'Professional Tax' => 'professional_tax',
            'Income Tax (TDS)' => 'tds',
            'Educational Cess' => 'educational_cess',
            'Health Insurance' => 'health_insurance',
            'Other Deductions' => 'other_deductions',
            'Loss of Pay' => 'lop_deduction',
        ];

        $rows = [];
        foreach ($candidates as $label => $field) {
            $values = [];
            $total = 0.0;
            foreach ($months as $m) {
                $amount = $m['slip'] ? (float) $m['slip']->{$field} : null;
                $values[] = $amount;
                $total += (float) $amount;
            }
            if ($total > 0) {
                $rows[] = ['label' => $label, 'values' => $values, 'total' => $total];
            }
        }

        return $rows;
    }

    /** A single computed row (e.g. Net Pay) derived from each month's Payslip via $compute. */
    private function buildScalarRow(array $months, \Closure $compute): array
    {
        $values = [];
        $total = 0.0;
        foreach ($months as $m) {
            $amount = $m['slip'] ? (float) $compute($m['slip']) : null;
            $values[] = $amount;
            $total += (float) $amount;
        }

        return ['values' => $values, 'total' => $total];
    }

    private function payslipViewData(Payslip $slip): array
    {
        $earnings = $slip->components->where('category', 'earning')->sortBy('sequence')->values();
        $ctcAddons = $slip->components->where('category', 'ctc_addon')->sortBy('sequence')->values();
        $byCode = $slip->components->keyBy('code');

        // Resolves a percentage component's base name for the "(25% from Basic Pay)"
        // label, using this payslip's own snapshot — never the live template, so an
        // old payslip's label can't drift if the template changes later.
        $percentageLabel = function ($component) use ($byCode) {
            if ($component->calc_type !== 'percentage' || $component->value === null) {
                return null;
            }
            $baseName = $component->percentage_of === \App\Models\SalaryComponent::GROSS
                ? 'Gross'
                : ($byCode[$component->percentage_of]->name ?? $component->percentage_of);

            return rtrim(rtrim(number_format((float) $component->value, 2), '0'), '.') . "% from {$baseName}";
        };

        $ctcAddonTotal = (float) $ctcAddons->sum('amount');
        $payableGross = (float) $slip->gross_salary - (float) $slip->lop_deduction;

        return [
            'slip' => $slip,
            'employee' => $slip->employee,
            'run' => $slip->run,
            'earnings' => $earnings,
            'ctcAddons' => $ctcAddons,
            'percentageLabel' => $percentageLabel,
            'totalCtc' => round($payableGross + $ctcAddonTotal, 2),
            'netPayBeforeReimbursement' => round((float) $slip->net_pay - (float) $slip->reimbursements, 2),
            'letterhead' => $this->letterhead(),
            'generatedAt' => now(),
        ];
    }
}
