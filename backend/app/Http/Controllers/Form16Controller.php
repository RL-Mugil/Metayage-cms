<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Form16Document;
use App\Models\User;
use App\Services\IncomeTaxComputationService;
use App\Support\Notifier;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use setasign\Fpdi\Fpdi;
use ZipArchive;

/**
 * Form 16 — matches the real, confirmed industry pattern (verified against
 * the user's own Zoho Payroll screenshot): no payroll product, ours
 * included, can legally generate a valid Form 16 end-to-end. Since May 2019
 * (CBDT Circulars 03/2011, 01/2012, 04/2013) only TRACES — driven by the
 * employer's own quarterly Form 24Q filing — may issue Part A and Part B.
 * What we actually do: compute Part B ourselves (IncomeTaxComputationService),
 * accept the TRACES-downloaded Part A ZIP (PAN-named PDFs) HR already has
 * from the TRACES utility, match by PAN, and merge the two into one PDF per
 * employee via FPDI — identical to what Zoho's own "Generate Form 16" screen
 * does. We never scrape or log into TRACES ourselves (see AGENTS.md security
 * rules — no RPA against a government tax portal with the firm's credentials).
 */
class Form16Controller extends Controller
{
    private const MANAGE_ROLES = User::PAYROLL_ACCESS_ROLES;
    private const VIEW_ROLES = User::PAYROLL_ACCESS_ROLES;

    public function __construct(private IncomeTaxComputationService $taxService)
    {
    }

    private function letterhead(): array
    {
        $rows = DB::table('system_settings')
            ->whereIn('key', ['payroll_company_name', 'payroll_company_address', 'payroll_company_tan', 'payroll_signatory_name', 'payroll_signatory_designation'])
            ->pluck('value', 'key');

        return [
            'company_name' => $rows['payroll_company_name'] ?? '',
            'company_address' => $rows['payroll_company_address'] ?? '',
            'company_tan' => $rows['payroll_company_tan'] ?? '',
            'signatory_name' => $rows['payroll_signatory_name'] ?? '',
            'signatory_designation' => $rows['payroll_signatory_designation'] ?? '',
        ];
    }

    /**
     * Maps the computed tax figures onto the exact item numbers of the
     * official Form 16 Part B Annexure (CBDT-prescribed layout — "Details of
     * Salary Paid and any other income and tax deducted", items 1-19).
     *
     * A value of `null` means the item is genuinely not tracked by this
     * system (e.g. perquisites, gratuity/leave-encashment/commuted-pension —
     * employer-computed, not employee-declared) or not applicable under the
     * employee's chosen regime (e.g. HRA/LTA exemption, Chapter VI-A
     * deductions — all disallowed under the new regime, Income Tax Act 2025
     * §202(2)(a)) — the template renders these as the official form's blank
     * "Rs. ..." placeholder rather than fabricating a zero. Every other
     * value is a real, computed figure.
     */
    private function buildPartBLineItems(array $tax): array
    {
        $old = $tax['regime'] === 'old';

        $item1a = (float) $tax['gross_annual_salary'];
        $item1d = $item1a; // 1(a)+1(b)+1(c) — (b)/(c) not tracked
        $item1e = (float) $tax['previous_employer_salary']; // not regime-gated — a salary fact, not a deduction

        $item2a = $old ? (float) $tax['lta_exemption'] : null;
        $item2e = $old ? (float) $tax['hra_exemption'] : null;
        $item2h = $old ? (($item2a ?? 0.0) + ($item2e ?? 0.0)) : null; // 2(a)+...+2(e)+2(g) — only 2(a)/2(e) tracked

        // Floored at 0: neither the salary-received figure nor "Income chargeable
        // under Salaries" can legally go negative (section 16 deductions can't
        // create a loss from salary income) — matters when an employee has few
        // or no payslips yet in the financial year, so deductions can exceed
        // the (still accruing) salary total.
        $item3 = max(0.0, $item1a - ($item2h ?? 0.0));

        $item4a = (float) $tax['standard_deduction'];
        $item4c = $old ? (float) $tax['professional_tax_annual'] : null;
        $item5 = $item4a + ($item4c ?? 0.0);

        $item6 = max(0.0, $item3 + $item1e - $item5);

        $item7a = ($old && $tax['housing_type'] === 'self_occupied_loan')
            ? -(float) $tax['home_loan_interest_deduction']
            : null;
        $item7b = (float) $tax['other_income_annual'];
        $item8 = ($item7a ?? 0.0) + $item7b;

        $item9 = $item6 + $item8;

        $item10a = $old ? (float) $tax['section_80c'] : null;
        $item10d = $item10a; // 80C+80CCC+80CCD(1) — only 80C tracked
        $item10e = $old ? (float) $tax['section_80ccd1b'] : null;
        $item10g = $old ? (float) $tax['section_80d'] : null;
        $item10h = $old ? (float) $tax['section_80e'] : null;
        $item10i = $old ? (float) $tax['section_80g'] : null;
        $item10j = $old ? (float) $tax['section_80tta'] : null;

        // Item (k)/(l) — "amount deductible under any other provision(s) of Chapter
        // VI-A" is the official form's own catch-all for sections it doesn't print a
        // dedicated row for. Only non-zero items appear, each with its real Income
        // Tax Act 2025 citation (old-regime only, same gating as every other item here).
        $otherChapterVIA = [];
        if ($old) {
            $candidates = [
                ['label' => 'Section 127 (disabled dependant — erstwhile §80DD)', 'amount' => (float) $tax['section_80dd']],
                ['label' => 'Section 128 (specified disease treatment — erstwhile §80DDB)', 'amount' => (float) $tax['section_80ddb']],
                ['label' => 'Section 130 (home loan interest, first-time buyer — erstwhile §80EE)', 'amount' => (float) $tax['section_80ee']],
                ['label' => 'Section 131 (home loan interest, affordable housing — erstwhile §80EEA)', 'amount' => (float) $tax['section_80eea']],
                ['label' => 'Section 134 (rent paid, no HRA — erstwhile §80GG)', 'amount' => (float) $tax['section_80gg']],
                ['label' => 'Section 135 (donations for scientific research/rural development — erstwhile §80GGA)', 'amount' => (float) $tax['section_80gga']],
                ['label' => 'Section 137 (contribution to political parties — erstwhile §80GGC)', 'amount' => (float) $tax['section_80ggc']],
            ];
            $otherChapterVIA = array_values(array_filter($candidates, fn (array $c) => $c['amount'] > 0));
        }
        $item10k = array_sum(array_column($otherChapterVIA, 'amount'));
        $item10l = count($otherChapterVIA) > 0 ? round($item10k, 2) : null;

        $item11 = (float) $tax['chapter_via_deductions']; // already regime-gated to 0 under new regime

        $item12 = (float) $tax['taxable_income'];
        $item13 = (float) $tax['slab_tax'];
        $item14 = (float) $tax['rebate_87a'];
        $item16 = (float) $tax['cess'];
        $item17 = (float) $tax['annual_tax_liability']; // 13+15(untracked)+16-14
        $item19 = $item17; // 17-18(untracked)

        return [
            '1a' => $item1a, '1b' => null, '1c' => null, '1d' => $item1d, '1e' => $item1e,
            '2a' => $item2a, '2b' => null, '2c' => null, '2d' => null, '2e' => $item2e, '2g' => null, '2h' => $item2h,
            '3' => $item3,
            '4a' => $item4a, '4b' => null, '4c' => $item4c,
            '5' => $item5,
            '6' => $item6,
            '7a' => $item7a, '7b' => $item7b,
            '8' => $item8,
            '9' => $item9,
            '10a' => $item10a, '10b' => null, '10c' => null, '10d' => $item10d,
            '10e' => $item10e, '10f' => null, '10g' => $item10g, '10h' => $item10h,
            '10i' => $item10i, '10j' => $item10j, '10k_items' => $otherChapterVIA, '10l' => $item10l,
            '11' => $item11,
            '12' => $item12,
            '13' => $item13,
            '14' => $item14,
            '15' => null,
            '16' => $item16,
            '17' => $item17,
            '18' => null,
            '19' => $item19,
        ];
    }

    /** The per-employee match/merge status table — also serves as "upload results". */
    public function index(Request $request, $financialYear)
    {
        if (! in_array($request->user()->role, self::VIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $docs = Form16Document::where('financial_year', $financialYear)
            ->with('employee:id,full_name,employee_code,pan_tax_id,pan_verification_status')
            ->get();

        return response()->json($docs);
    }

    /**
     * HR uploads the TRACES ZIP (PAN-named Part A PDFs, ≤25MB — the same
     * constraints as the real TRACES export, not ours). Matches each file to
     * an employee by PAN, stores it, and immediately generates/merges Part B.
     */
    public function uploadPartAZip(Request $request, $financialYear)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'zip' => 'required|file|mimes:zip|max:25600',
        ]);

        $financialYear = (int) $financialYear;
        $zipPath = $request->file('zip')->getRealPath();
        $extractDir = storage_path('app/tmp/form16-extract-' . uniqid());
        @mkdir($extractDir, 0755, true);

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return response()->json(['message' => 'Could not open the uploaded ZIP file.'], 422);
        }
        $zip->extractTo($extractDir);
        $zip->close();

        // PAN -> Employee, built in memory since pan_tax_id is encrypted at rest
        // and can't be matched with a SQL WHERE.
        $employeesByPan = Employee::whereNotNull('pan_tax_id')->get()
            ->filter(fn ($e) => filled($e->pan_tax_id))
            ->keyBy(fn ($e) => strtoupper($e->pan_tax_id));

        $matched = [];
        $unmatched = [];

        foreach (glob("{$extractDir}/*.pdf") ?: [] as $filePath) {
            $pan = strtoupper(pathinfo($filePath, PATHINFO_FILENAME));
            $employee = $employeesByPan->get($pan);

            if (! $employee) {
                $unmatched[] = basename($filePath);
                continue;
            }

            $partAPath = "form16/{$financialYear}/part_a/{$employee->id}.pdf";
            Storage::disk('local')->put($partAPath, file_get_contents($filePath));

            Form16Document::updateOrCreate(
                ['employee_id' => $employee->id, 'financial_year' => $financialYear],
                ['part_a_path' => $partAPath, 'pan_matched' => true]
            );

            $this->generateFor($employee, $financialYear);
            $matched[] = ['employee_code' => $employee->employee_code, 'full_name' => $employee->full_name, 'pan' => $pan];
        }

        array_map('unlink', glob("{$extractDir}/*") ?: []);
        @rmdir($extractDir);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'upload_form16_part_a',
            'subject_type' => 'Form16Document', 'subject_id' => $financialYear,
            'metadata' => ['matched_count' => count($matched), 'unmatched_count' => count($unmatched)],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['matched' => $matched, 'unmatched' => $unmatched]);
    }

    /** Renders Part B, merging with an existing Part A if one was already uploaded. */
    public function generate(Request $request, $employeeId, $financialYear)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $employee = Employee::findOrFail($employeeId);
        $doc = $this->generateFor($employee, (int) $financialYear);

        return response()->json($doc);
    }

    private function generateFor(Employee $employee, int $financialYear): Form16Document
    {
        // Matches Zoho's own prerequisite: "You cannot generate Form 16 for
        // employees without PAN." Fail honestly instead of rendering a
        // certificate with a blank PAN field.
        if (blank($employee->pan_tax_id)) {
            $doc = Form16Document::firstOrNew(['employee_id' => $employee->id, 'financial_year' => $financialYear]);
            $doc->status = Form16Document::STATUS_MISSING_PAN;
            $doc->save();

            return $doc;
        }

        $tax = $this->taxService->computeForEmployee($employee, $financialYear);
        $fyLabel = "{$financialYear}-" . substr((string) ($financialYear + 1), -2);

        $html = Pdf::loadView('payroll.form16-part-b', [
            'employee' => $employee,
            'fiscalYearLabel' => $fyLabel,
            'tax' => $tax,
            'lineItems' => isset($tax['error']) ? null : $this->buildPartBLineItems($tax),
            'letterhead' => $this->letterhead(),
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');

        $partBPath = "form16/{$financialYear}/part_b/{$employee->id}.pdf";
        Storage::disk('local')->put($partBPath, $html->output());

        $doc = Form16Document::firstOrNew(['employee_id' => $employee->id, 'financial_year' => $financialYear]);
        $doc->part_b_path = $partBPath;

        if ($doc->part_a_path && Storage::disk('local')->exists($doc->part_a_path)) {
            try {
                $mergedPath = "form16/{$financialYear}/merged/{$employee->id}.pdf";
                $this->mergePdfs(
                    Storage::disk('local')->path($doc->part_a_path),
                    Storage::disk('local')->path($partBPath),
                    Storage::disk('local')->path($mergedPath)
                );
                $doc->merged_path = $mergedPath;
                $doc->status = Form16Document::STATUS_MERGED;
            } catch (\Throwable $e) {
                $doc->status = Form16Document::STATUS_FAILED;
            }
        } else {
            $doc->status = Form16Document::STATUS_PART_B_ONLY;
        }

        $doc->save();

        return $doc;
    }

    /** Imports every page of both PDFs into one combined document via FPDI. */
    private function mergePdfs(string $partAAbsPath, string $partBAbsPath, string $mergedAbsPath): void
    {
        @mkdir(dirname($mergedAbsPath), 0755, true);

        $pdf = new Fpdi();
        foreach ([$partAAbsPath, $partBAbsPath] as $source) {
            $pageCount = $pdf->setSourceFile($source);
            for ($i = 1; $i <= $pageCount; $i++) {
                $templateId = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($templateId);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($templateId);
            }
        }
        $pdf->Output($mergedAbsPath, 'F');
    }

    /**
     * Employee self-service — asks accounts to prepare their Form 16 for a
     * financial year. Creates a STATUS_REQUESTED placeholder if nothing
     * exists yet so it surfaces in the Payroll Portal's Form 16 list; never
     * downgrades a document that's already been generated.
     */
    public function request(Request $request, $financialYear)
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();
        if (! $employee) {
            return response()->json(['message' => 'No employee record linked to your account.'], 403);
        }

        $doc = Form16Document::firstOrCreate(
            ['employee_id' => $employee->id, 'financial_year' => $financialYear],
            ['status' => Form16Document::STATUS_REQUESTED]
        );

        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => 'request_form16',
            'subject_type' => 'Form16Document', 'subject_id' => $doc->id,
            'metadata' => ['employee_id' => $employee->id, 'financial_year' => $financialYear],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        if ($doc->wasRecentlyCreated) {
            Notifier::push(
                User::payrollAccessUserIds(),
                'form16_request',
                'Form 16 requested',
                "{$employee->full_name} requested their Form 16 for FY {$financialYear}-" . substr((string) ($financialYear + 1), -2),
                '/payroll-portal/form-16',
                ['employee_id' => $employee->id, 'financial_year' => $financialYear],
            );
        }

        return response()->json($doc);
    }

    /**
     * Employee self-service download — merged PDF if available, else Part B
     * alone. Gated on `published_at`: a payroll-access reviewer can preview
     * an unpublished document, but an employee can't see it until it's
     * explicitly published (matches Zoho's Upload → Generate → Publish gate).
     */
    public function download(Request $request, $employeeId, $financialYear)
    {
        $user = $request->user();
        $isReviewer = in_array($user->role, self::VIEW_ROLES, true);
        if (! $isReviewer) {
            $own = Employee::where('user_id', $user->id)->first();
            if (! $own || (int) $own->id !== (int) $employeeId) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $doc = Form16Document::where('employee_id', $employeeId)->where('financial_year', $financialYear)->firstOrFail();

        if (! $isReviewer && ! $doc->published_at) {
            return response()->json(['message' => 'Your Form 16 has not been published yet.'], 403);
        }

        $path = $doc->merged_path ?: $doc->part_b_path;

        if (! $path || ! Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        return Storage::disk('local')->download($path, "form16-{$employeeId}-{$financialYear}.pdf");
    }

    /** Publishes one employee's generated Form 16, making it visible to them. */
    public function publish(Request $request, $employeeId, $financialYear)
    {
        if (! in_array($request->user()->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $doc = Form16Document::where('employee_id', $employeeId)->where('financial_year', $financialYear)->firstOrFail();
        if (! in_array($doc->status, [Form16Document::STATUS_MERGED, Form16Document::STATUS_PART_B_ONLY], true)) {
            return response()->json(['message' => 'This document has not been generated yet.'], 422);
        }

        $doc->update(['published_at' => now()]);

        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => 'publish_form16',
            'subject_type' => 'Form16Document', 'subject_id' => $doc->id,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        $doc->load('employee:id,full_name,user_id');
        if ($doc->employee?->user_id) {
            Notifier::push($doc->employee->user_id, 'form16_published', 'Form 16 published',
                "Your Form 16 for FY {$financialYear}-" . substr((string) ($financialYear + 1), -2) . ' is ready to download.',
                '/hrms/payroll');
        }

        return response()->json($doc);
    }

    /** Publishes every generated (Merged/PartBOnly) document for a financial year in one go. */
    public function publishAll(Request $request, $financialYear)
    {
        if (! in_array($request->user()->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $docs = Form16Document::where('financial_year', $financialYear)
            ->whereIn('status', [Form16Document::STATUS_MERGED, Form16Document::STATUS_PART_B_ONLY])
            ->whereNull('published_at')
            ->with('employee:id,full_name,user_id')
            ->get();

        foreach ($docs as $doc) {
            $doc->update(['published_at' => now()]);
            if ($doc->employee?->user_id) {
                Notifier::push($doc->employee->user_id, 'form16_published', 'Form 16 published',
                    "Your Form 16 for FY {$financialYear}-" . substr((string) ($financialYear + 1), -2) . ' is ready to download.',
                    '/hrms/payroll');
            }
        }

        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => 'publish_form16_batch',
            'subject_type' => 'Form16Document', 'subject_id' => (int) $financialYear,
            'metadata' => ['published_count' => $docs->count()],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['published_count' => $docs->count()]);
    }

    /**
     * 24Q annexure export — the step BEFORE HR goes to TRACES, not a filing
     * API (none exists). Saves re-typing what's already in the system when
     * filing the quarterly return that TRACES later issues Part A from.
     */
    public function export24QData(Request $request, $financialYear)
    {
        if (! in_array($request->user()->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $financialYear = (int) $financialYear;
        $employees = Employee::where('employment_status', 'Active')->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('24Q Annexure');
        $headers = ['Employee Code', 'Full Name', 'PAN', 'Gross Annual Salary', 'Taxable Income', 'Annual Tax Liability', 'TDS Withheld', 'Balance Payable'];
        $sheet->fromArray($headers, null, 'A1');

        $row = 2;
        foreach ($employees as $employee) {
            $tax = $this->taxService->computeForEmployee($employee, $financialYear);
            if (isset($tax['error'])) {
                continue;
            }
            $sheet->fromArray([
                $employee->employee_code,
                $employee->full_name,
                $employee->pan_tax_id,
                $tax['gross_annual_salary'],
                $tax['taxable_income'],
                $tax['annual_tax_liability'],
                $tax['tds_already_withheld'],
                $tax['balance_tax_payable'],
            ], null, "A{$row}");
            $row++;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), '24q') . '.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);

        return response()->download($tmpPath, "form-24q-annexure-{$financialYear}.xlsx")->deleteFileAfterSend(true);
    }
}
