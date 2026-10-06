<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\InvestmentDeclaration;
use App\Models\PoiWindow;
use App\Models\ProofOfInvestment;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Proof of Investments — the separate, later, document-backed review phase
 * that follows IT Declaration (InvestmentDeclarationController). Employer
 * reviews declared figures, enters an approved amount per section (may be
 * less than declared), and only an explicit "Consider for IT" lock-in makes
 * the approved figures — not the declared ones — drive actual TDS (see
 * IncomeTaxComputationService). InvestmentDeclaration.status stays untouched
 * by any of this; it still only governs the year-start estimate.
 */
class ProofOfInvestmentController extends Controller
{
    private const REVIEW_ROLES = User::PAYROLL_ACCESS_ROLES;

    /** Any authenticated employee can check the current window for a FY. */
    public function window(Request $request, $financialYear)
    {
        return response()->json(PoiWindow::where('financial_year', $financialYear)->first());
    }

    public function windows(Request $request)
    {
        if (! in_array($request->user()->role, self::REVIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(PoiWindow::orderByDesc('financial_year')->get());
    }

    public function storeWindow(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, self::REVIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'financial_year' => 'required|integer|min:2000|max:2100',
            'deadline_at' => 'required|date',
            'effective_payroll_month' => 'required|date',
        ]);

        $window = PoiWindow::updateOrCreate(
            ['financial_year' => $validated['financial_year']],
            [
                'deadline_at' => $validated['deadline_at'],
                'effective_payroll_month' => $validated['effective_payroll_month'],
                'updated_by_id' => $user->id,
            ]
        );

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'update_poi_window',
            'subject_type' => 'PoiWindow', 'subject_id' => $window->id,
            'metadata' => $validated,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($window);
    }

    /** Employee self-service — get-or-create their own POI row for a FY (requires an existing declaration). */
    public function mine(Request $request, $financialYear)
    {
        $employee = Employee::where('user_id', $request->user()->id)->firstOrFail();
        $declaration = InvestmentDeclaration::where('employee_id', $employee->id)
            ->where('financial_year', $financialYear)
            ->firstOrFail();

        $poi = ProofOfInvestment::with('documents')->firstOrCreate(
            ['employee_id' => $employee->id, 'financial_year' => $financialYear],
            ['investment_declaration_id' => $declaration->id]
        );

        return response()->json($poi);
    }

    /** Employee self-service — upload one proof document for one declared section. Blocked once the window closes. */
    public function uploadDocument(Request $request, $financialYear, $section)
    {
        $employee = Employee::where('user_id', $request->user()->id)->firstOrFail();

        $window = PoiWindow::where('financial_year', $financialYear)->first();
        if (! $window || ! $window->isOpen()) {
            return response()->json(['message' => 'The proof-of-investment submission window is closed.'], 422);
        }

        $declaration = InvestmentDeclaration::where('employee_id', $employee->id)
            ->where('financial_year', $financialYear)
            ->firstOrFail();

        $request->validate(['file' => 'required|file|max:10240']);

        $poi = ProofOfInvestment::firstOrCreate(
            ['employee_id' => $employee->id, 'financial_year' => $financialYear],
            ['investment_declaration_id' => $declaration->id]
        );

        $file = $request->file('file');
        $path = "poi/{$financialYear}/{$employee->id}/{$section}/" . uniqid() . '.' . $file->extension();
        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        $doc = $poi->documents()->create([
            'section' => $section,
            'storage_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'uploaded_by_id' => $request->user()->id,
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => 'upload_poi_document',
            'subject_type' => 'ProofOfInvestment', 'subject_id' => $poi->id,
            'metadata' => ['section' => $section],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        Notifier::push(
            User::payrollAccessUserIds(),
            'poi_document',
            'Proof of investment uploaded',
            "{$employee->full_name} uploaded a {$section} proof document for FY {$financialYear}-" . substr((string) ($financialYear + 1), -2),
            '/payroll-portal/approvals/proof-of-investments',
            ['poi_id' => $poi->id, 'employee_id' => $employee->id],
        );

        return response()->json($doc, 201);
    }

    /** Employee self-service — remove their own still-unreviewed upload. */
    public function deleteDocument(Request $request, $documentId)
    {
        $employee = Employee::where('user_id', $request->user()->id)->firstOrFail();
        $doc = \App\Models\InvestmentProofDocument::with('proofOfInvestment')->findOrFail($documentId);

        if ((int) $doc->proofOfInvestment->employee_id !== (int) $employee->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($doc->proofOfInvestment->isConsidered()) {
            return response()->json(['message' => 'Already considered for IT — documents can no longer be changed.'], 422);
        }

        Storage::disk('local')->delete($doc->storage_path);
        $doc->delete();

        return response()->json(['ok' => true]);
    }

    /** Employee self-service — download their own document (or HR downloading any employee's). */
    public function downloadDocument(Request $request, $documentId)
    {
        $doc = \App\Models\InvestmentProofDocument::with('proofOfInvestment.employee')->findOrFail($documentId);

        if (! in_array($request->user()->role, self::REVIEW_ROLES, true)) {
            $employee = Employee::where('user_id', $request->user()->id)->first();
            if (! $employee || (int) $doc->proofOfInvestment->employee_id !== (int) $employee->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        if (! Storage::disk('local')->exists($doc->storage_path)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        return Storage::disk('local')->download($doc->storage_path, $doc->original_filename);
    }

    /** HR review queue — still-PendingReview POI rows for a FY. */
    public function pending(Request $request, $financialYear)
    {
        if (! in_array($request->user()->role, self::REVIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $rows = ProofOfInvestment::where('financial_year', $financialYear)
            ->where('status', ProofOfInvestment::STATUS_PENDING_REVIEW)
            ->with('employee:id,full_name,employee_code', 'declaration', 'documents')
            ->get();

        return response()->json($rows);
    }

    public function forEmployee(Request $request, $employeeId)
    {
        if (! in_array($request->user()->role, self::REVIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $rows = ProofOfInvestment::where('employee_id', $employeeId)
            ->with('declaration', 'documents')
            ->orderByDesc('financial_year')
            ->get();

        return response()->json($rows);
    }

    /** HR enters per-section approved amounts + optional rejection reasons — does NOT lock in; stays revisable. */
    public function setApprovedAmounts(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, self::REVIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $poi = ProofOfInvestment::findOrFail($id);
        if ($poi->isConsidered()) {
            return response()->json(['message' => 'Already considered for IT — no further edits.'], 422);
        }

        $validated = $request->validate([
            'approved_section_80c_amount' => 'nullable|numeric|min:0',
            'approved_section_80ccd1b_amount' => 'nullable|numeric|min:0',
            'approved_section_80d_amount' => 'nullable|numeric|min:0',
            'approved_section_80e_amount' => 'nullable|numeric|min:0',
            'approved_section_80g_amount' => 'nullable|numeric|min:0',
            'approved_section_80tta_amount' => 'nullable|numeric|min:0',
            'approved_hra_rent_paid' => 'nullable|numeric|min:0',
            'approved_home_loan_interest_amount' => 'nullable|numeric|min:0',
            'approved_other_income_amount' => 'nullable|numeric|min:0',
            'approved_previous_employer_salary' => 'nullable|numeric|min:0',
            'approved_lta_exemption_claimed' => 'nullable|numeric|min:0',
            'approved_section_80gg_amount' => 'nullable|numeric|min:0',
            'approved_section_80dd_amount' => 'nullable|numeric|min:0',
            'approved_section_80ddb_amount' => 'nullable|numeric|min:0',
            'approved_section_80ee_amount' => 'nullable|numeric|min:0',
            'approved_section_80eea_amount' => 'nullable|numeric|min:0',
            'approved_section_80gga_amount' => 'nullable|numeric|min:0',
            'approved_section_80ggc_amount' => 'nullable|numeric|min:0',
            'approved_other_deductions_json' => 'nullable|array',
            'rejection_reasons_json' => 'nullable|array',
        ]);

        $poi->update($validated);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'set_poi_approved_amounts',
            'subject_type' => 'ProofOfInvestment', 'subject_id' => $poi->id,
            'metadata' => $validated,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($poi);
    }

    /** Explicit lock-in — irreversible for tax-computation purposes. */
    public function considerForIt(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, self::REVIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $poi = ProofOfInvestment::findOrFail($id);
        if ($poi->isConsidered()) {
            return response()->json(['message' => 'Already considered for IT.'], 422);
        }

        $poi->update([
            'status' => ProofOfInvestment::STATUS_CONSIDERED,
            'consider_for_it_at' => now(),
            'considered_by_id' => $user->id,
        ]);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'consider_poi_for_it',
            'subject_type' => 'ProofOfInvestment', 'subject_id' => $poi->id,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        $poi->load('employee:id,full_name,user_id');
        if ($poi->employee?->user_id) {
            Notifier::push($poi->employee->user_id, 'poi_considered', 'Proof of investments finalized',
                'Your reviewed investment proof amounts are now locked in for tax computation.', '/hrms/payroll');
        }

        return response()->json($poi);
    }

    /** Bulk — accept every still-PendingReview row's declared figures as approved, then lock in, in one step. */
    public function approveAllAndConsider(Request $request, $financialYear)
    {
        $user = $request->user();
        if (! in_array($user->role, self::REVIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $rows = ProofOfInvestment::where('financial_year', $financialYear)
            ->where('status', ProofOfInvestment::STATUS_PENDING_REVIEW)
            ->with('declaration', 'employee:id,full_name,user_id')
            ->get();

        foreach ($rows as $poi) {
            $d = $poi->declaration;
            $poi->update([
                'approved_section_80c_amount' => $d->section_80c_amount,
                'approved_section_80ccd1b_amount' => $d->section_80ccd1b_amount,
                'approved_section_80d_amount' => $d->section_80d_amount,
                'approved_section_80e_amount' => $d->section_80e_amount,
                'approved_section_80g_amount' => $d->section_80g_amount,
                'approved_section_80tta_amount' => $d->section_80tta_amount,
                'approved_hra_rent_paid' => $d->hra_rent_paid,
                'approved_home_loan_interest_amount' => $d->home_loan_interest_declared,
                'approved_other_income_amount' => $d->other_income_declared,
                'approved_previous_employer_salary' => $d->previous_employer_salary,
                'approved_lta_exemption_claimed' => $d->lta_exemption_claimed,
                'approved_section_80gg_amount' => $d->section_80gg_amount,
                'approved_section_80dd_amount' => $d->section_80dd_amount,
                'approved_section_80ddb_amount' => $d->section_80ddb_amount,
                'approved_section_80ee_amount' => $d->section_80ee_amount,
                'approved_section_80eea_amount' => $d->section_80eea_amount,
                'approved_section_80gga_amount' => $d->section_80gga_amount,
                'approved_section_80ggc_amount' => $d->section_80ggc_amount,
                'status' => ProofOfInvestment::STATUS_CONSIDERED,
                'consider_for_it_at' => now(),
                'considered_by_id' => $user->id,
            ]);
            if ($poi->employee?->user_id) {
                Notifier::push($poi->employee->user_id, 'poi_considered', 'Proof of investments finalized',
                    'Your declared investment amounts have been accepted and locked in for tax computation.', '/hrms/payroll');
            }
        }

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'approve_all_poi',
            'subject_type' => 'ProofOfInvestment', 'subject_id' => (int) $financialYear,
            'metadata' => ['count' => $rows->count()],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['considered_count' => $rows->count()]);
    }
}
