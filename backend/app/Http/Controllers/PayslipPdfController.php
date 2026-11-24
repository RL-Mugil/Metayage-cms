<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Services\InternalDocumentSignatureService;
use App\Services\Payroll\PayslipSigner;
use App\Services\Reports\SignatureSeal;
use App\Models\InternalDocumentSignature;
use App\Models\ReportExport;

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

        $head = app(\App\Services\Letterhead::class)->get();

        return [
            'company_name' => ($rows['payroll_company_name'] ?? '') ?: $head['company_name'],
            'company_address' => ($rows['payroll_company_address'] ?? '') ?: $head['address'],
            'company_logo_url' => $rows['payroll_company_logo_url'] ?? '',
            // Company letterhead: logo on top, footer (name, address, phone, web) at the bottom.
            'logo_data_uri' => $head['logo_data_uri'],
            'footer_name' => $head['company_name'],
            'footer_address' => $head['address'],
            'footer_phone' => $head['phone'],
            'footer_web' => $head['web'],
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
        $slip = Payslip::with(['components', 'employee.designation', 'run:id,period,status,run_type', 'internalSignature.signedBy'])->findOrFail($payslipId);
        $verified = app(PayslipSigner::class)->isSigned($slip);

        if (! $this->canViewAny($request)) {
            $own = $this->resolveOwnEmployee($request);
            if (! $own || (int) $slip->employee_id !== (int) $own->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            if (! in_array($slip->run->status, ['Finalized', 'Paid'], true)) {
                return response()->json(['message' => 'This payslip is not finalized yet.'], 403);
            }
        }
        if (! $verified && ! $this->canViewAny($request)) {
            return response()->json(['message' => 'This payslip is not signed and published yet.'], 422);
        }

        return $this->renderMonthlyPdf($slip)->download("payslip-{$slip->employee_code}-{$slip->run->period->format('Y-m')}.pdf");
    }

    /** Sign one payslip with the payroll signatory's PIN (Payroll Portal → pay run → payslip). */
    public function signMonthly(Request $request, $payslipId, PayslipSigner $signer)
    {
        $user = $request->user();
        if (! $this->canViewAny($request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $validated = $request->validate(['pin' => 'required|digits:4']);
        $slip = Payslip::with('components', 'run:id,period,status', 'internalSignature')->findOrFail($payslipId);
        if (! in_array($slip->run->status, ['Finalized', 'Paid'], true)) {
            return response()->json(['message' => 'Only finalized or paid payslips can be signed.'], 422);
        }
        $signer->signMany(collect([$slip]), $validated['pin']);
        $signature = $signer->signatureFor($slip);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'sign_internal_payslip',
            'subject_type' => 'Payslip', 'subject_id' => $slip->id,
            'metadata' => ['verification_code' => $signature?->verification_code, 'signatory' => $signature?->signer_name],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['message' => 'Payslip signed and published.', 'signature' => $signature?->load('signedBy:id,name')]);
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

    /** Kept for callers of the old API; the fingerprint now lives in PayslipSigner. */
    public function monthlyFingerprint(Payslip $slip, ?InternalDocumentSignatureService $signatures = null): string
    {
        return app(PayslipSigner::class)->fingerprint($slip);
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
            ->with(['payslips' => fn ($q) => $q->where('employee_id', $employee->id)->with(['components', 'internalSignature.signedBy'])])
            ->get();

        // Only signed, published months go into the statement (a run's month may hold a
        // Regular and a Manual run — the signed payslip of either is the month's payslip).
        $signer = app(PayslipSigner::class);
        $signedSlips = $runs->flatMap(fn ($run) => $run->payslips->each(fn ($p) => $p->setRelation('run', $run)))
            ->filter(fn ($slip) => in_array($slip->run->status, ['Finalized', 'Paid'], true) && $signer->isSigned($slip))
            ->keyBy(fn ($slip) => Carbon::parse($slip->run->period)->format('Y-m'));
        if ($signedSlips->isEmpty()) {
            return response()->json(['message' => 'There are no signed payslips in this period yet.'], 422);
        }

        // One column per month in range, even for months with no processed run
        // yet — the sample format shows those as "NA", not an omitted column.
        $months = [];
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addMonthNoOverflow()) {
            $slip = $signedSlips->get($cursor->format('Y-m'));
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
            'deductionRows' => [...$this->buildComponentRows($months, 'deduction'), ...$this->buildDeductionRows($months)],
            'totalCtcRow' => $this->buildScalarRow($months, fn ($slip) => (float) $slip->gross_salary - (float) $slip->lop_deduction
                + $slip->components->where('category', 'ctc_addon')->sum('amount')),
            'totalDeductionsRow' => $this->buildScalarRow($months, fn ($slip) => (float) $slip->total_deductions),
            // net_pay excludes reimbursements (paid on top) — same as the monthly payslip.
            'netPayRow' => $this->buildScalarRow($months, fn ($slip) => (float) $slip->net_pay),
            'reimbursementsRow' => $this->buildScalarRow($months, fn ($slip) => (float) $slip->reimbursements),
            'creditedRow' => $this->buildScalarRow($months, fn ($slip) => (float) $slip->net_pay + (float) $slip->reimbursements),
            'letterhead' => $this->letterhead(),
            'generatedAt' => now(),
            'verifiedSlipCount' => $signedSlips->count(),
        ];
        $statement = $this->signStatement($request, $employee, $start, $end, $periodLabel, $signedSlips);
        $data['seal'] = app(SignatureSeal::class)->dataUri(true);
        $data['sig'] = $this->sealLines($statement);

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
        $signature = $slip->internalSignature?->loadMissing('signedBy');
        $signed = app(PayslipSigner::class)->isSigned($slip);

        return [
            'slip' => $slip,
            'employee' => $slip->employee,
            'run' => $slip->run,
            'earnings' => $earnings,
            'ctcAddons' => $ctcAddons,
            'percentageLabel' => $percentageLabel,
            'totalCtc' => round($payableGross + $ctcAddonTotal, 2),
            'netPayBeforeReimbursement' => round((float) $slip->net_pay, 2),
            'creditedToBank' => round((float) $slip->net_pay + (float) $slip->reimbursements, 2),
            'internalSignature' => $signature,
            'deductionComponents' => $slip->components->where('category', 'deduction')->sortBy('sequence')->values(),
            'seal' => app(SignatureSeal::class)->dataUri($signed),
            'sig' => $signed ? $this->sealLines($signature) : null,
            'letterhead' => $this->letterhead(),
            'generatedAt' => now(),
        ];
    }

    /** The three short lines beside the seal, from the signature itself (who signed then, as then titled). */
    private function sealLines(?InternalDocumentSignature $signature): ?array
    {
        if (! $signature) {
            return null;
        }

        return [
            'name' => $signature->signer_name ?: ($signature->signedBy?->name ?? ''),
            'designation' => (string) $signature->signer_designation,
            'at' => $signature->signed_at?->timezone('Asia/Kolkata')->format('d M Y, h:i A').' IST',
            'code' => $signature->verification_code,
            'url' => app(PayslipSigner::class)->verifyUrl($signature->verification_code),
        ];
    }

    /**
     * A consolidated statement is a compilation of payslips the signatory already signed
     * with their PIN; it is stamped by the same signatory with its own verification code,
     * which checks that every included payslip is still signed and unchanged.
     */
    private function signStatement(Request $request, Employee $employee, Carbon $start, Carbon $end, string $label, $signedSlips): InternalDocumentSignature
    {
        $latest = $signedSlips->sortByDesc(fn ($s) => $s->internalSignature->signed_at)->first()->internalSignature;
        $snapshot = [
            'employee_id' => $employee->id, 'employee_code' => $employee->employee_code, 'employee_name' => $employee->full_name,
            'from' => $start->format('Y-m'), 'to' => $end->format('Y-m'), 'label' => $label,
            'slips' => $signedSlips->map(fn ($s) => ['id' => $s->id, 'code' => $s->internalSignature->verification_code,
                'net_pay' => (float) $s->net_pay, 'period' => Carbon::parse($s->run->period)->format('Y-m')])->values()->all(),
        ];
        $export = ReportExport::create([
            'generated_by_id' => $request->user()->id, 'report_type' => 'payslip-statement',
            'report_name' => "Payslip statement — {$employee->full_name} ({$label})", 'format' => 'PDF',
            'filters' => ['employee_id' => $employee->id, 'from' => $start->format('Y-m'), 'to' => $end->format('Y-m')],
            'row_count' => $signedSlips->count(), 'snapshot' => $snapshot,
        ]);
        $signer = app(PayslipSigner::class);
        $signature = $signer->stamp(PayslipSigner::STATEMENT_TYPE, (int) $export->id, $this->statementFingerprint($export->id, $snapshot), [
            'user' => $latest->signedBy, 'name' => $latest->signer_name ?: $latest->signedBy?->name, 'designation' => (string) $latest->signer_designation,
        ]);
        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => 'payslip_statement',
            'subject_type' => 'Employee', 'subject_id' => $employee->id,
            'metadata' => ['from' => $snapshot['from'], 'to' => $snapshot['to'], 'payslips' => count($snapshot['slips']), 'verification_code' => $signature->verification_code],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return $signature;
    }

    private function statementFingerprint(int $exportId, array $snapshot): string
    {
        return app(InternalDocumentSignatureService::class)->fingerprint(PayslipSigner::STATEMENT_TYPE, $exportId, $snapshot);
    }

    /**
     * Public check of a payslip or payslip statement by its verification code (the link
     * printed beside the seal). Shows only what is needed to compare with the document.
     *
     * @return array<string, mixed>|null
     */
    public function verification(string $code): ?array
    {
        $signer = app(PayslipSigner::class);
        $signature = $signer->find($code);
        if (! $signature) {
            return null;
        }
        $base = [
            'code' => $signature->verification_code,
            'signed_by' => $signature->signer_name ?: $signature->signedBy?->name,
            'designation' => $signature->signer_designation,
            'signed_at' => $signature->signed_at?->timezone('Asia/Kolkata')->format('d M Y, h:i A').' IST',
        ];
        if ($signature->document_type === PayslipSigner::DOCUMENT_TYPE) {
            $slip = Payslip::with('components', 'run:id,period')->find($signature->document_id);
            if (! $slip) {
                return [...$base, 'kind' => 'Payslip', 'valid' => false];
            }

            return [...$base, 'kind' => 'Payslip', 'valid' => $signer->verify($signature, $signer->fingerprint($slip)),
                'employee' => $slip->employee_name.' ('.$slip->employee_code.')', 'period' => Carbon::parse($slip->run->period)->format('F Y'),
                'net_pay' => (float) $slip->net_pay];
        }
        $export = ReportExport::find($signature->document_id);
        $snapshot = (array) ($export?->snapshot ?? []);
        $valid = $export && $signer->verify($signature, $this->statementFingerprint($export->id, $snapshot));
        foreach ($snapshot['slips'] ?? [] as $row) {
            $slip = Payslip::with('components', 'run:id,period')->find($row['id']);
            $valid = $valid && $slip && $signer->isSigned($slip) && $signer->signatureFor($slip)?->verification_code === $row['code'];
        }

        return [...$base, 'kind' => 'Payslip statement', 'valid' => $valid,
            'employee' => ($snapshot['employee_name'] ?? '').' ('.($snapshot['employee_code'] ?? '').')', 'period' => $snapshot['label'] ?? '',
            'net_pay' => round(array_sum(array_column($snapshot['slips'] ?? [], 'net_pay')), 2)];
    }

    public function verify(string $code)
    {
        $result = $this->verification($code);
        abort_unless($result, 404, 'No payslip has this verification code.');

        return response()->json($result);
    }

    /** Public page: /verify/payslip/{code}. */
    public function verifyPage(string $code)
    {
        return response()->view('payslips.verify', ['result' => $this->verification($code), 'code' => strtoupper($code),
            'letterhead' => app(\App\Services\Letterhead::class)->get()]);
    }
}
