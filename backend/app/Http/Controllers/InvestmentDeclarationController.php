<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\InvestmentDeclaration;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Http\Request;

/**
 * Employee tax-saving investment declarations — own status/approved_by_id
 * columns (the real, actually-exercised pattern in this codebase, see
 * ExpenseClaim), not the polymorphic Approval table. Resolved via
 * ApprovalController::resolve() with type=Investment.
 */
class InvestmentDeclarationController extends Controller
{
    private const REVIEW_ROLES = User::PAYROLL_ACCESS_ROLES;

    /** Employee self-service — create or update their own declaration while it's still Draft. */
    public function store(Request $request)
    {
        $employee = Employee::where('user_id', $request->user()->id)->firstOrFail();

        $validated = $request->validate([
            'financial_year' => 'required|integer|min:2000|max:2100',
            'regime_choice' => 'required|in:old,new',
            'section_80c_amount' => 'nullable|numeric|min:0|max:150000',
            'section_80ccd1b_amount' => 'nullable|numeric|min:0|max:50000',
            'section_80d_amount' => 'nullable|numeric|min:0|max:100000',
            'section_80e_amount' => 'nullable|numeric|min:0',
            'section_80g_amount' => 'nullable|numeric|min:0',
            'section_80tta_amount' => 'nullable|numeric|min:0|max:10000',
            'hra_rent_paid' => 'nullable|numeric|min:0',
            'housing_type' => 'nullable|in:rent,self_occupied_loan',
            'home_loan_interest_declared' => 'nullable|numeric|min:0|max:200000',
            'other_income_declared' => 'nullable|numeric|min:0',
            'previous_employer_salary' => 'nullable|numeric|min:0',
            'lta_exemption_claimed' => 'nullable|numeric|min:0',
            'section_80gg_amount' => 'nullable|numeric|min:0|max:60000',
            'section_80dd_amount' => 'nullable|numeric|min:0|max:125000',
            'section_80ddb_amount' => 'nullable|numeric|min:0|max:100000',
            'section_80ee_amount' => 'nullable|numeric|min:0|max:50000',
            'section_80eea_amount' => 'nullable|numeric|min:0|max:150000',
            'section_80gga_amount' => 'nullable|numeric|min:0',
            'section_80ggc_amount' => 'nullable|numeric|min:0',
            'other_deductions_json' => 'nullable|array',
        ]);

        $declaration = InvestmentDeclaration::where('employee_id', $employee->id)
            ->where('financial_year', $validated['financial_year'])
            ->first();

        if ($declaration && $declaration->status !== InvestmentDeclaration::STATUS_DRAFT) {
            return response()->json(['message' => "This year's declaration is already {$declaration->status} and can no longer be edited."], 422);
        }

        if ($declaration) {
            $declaration->update($validated);
        } else {
            $declaration = InvestmentDeclaration::create([
                ...$validated,
                'employee_id' => $employee->id,
                'status' => InvestmentDeclaration::STATUS_DRAFT,
            ]);
        }

        return response()->json($declaration);
    }

    /** Locks the declaration to Submitted — no further employee edits until HR acts. */
    public function submit(Request $request, $id)
    {
        $employee = Employee::where('user_id', $request->user()->id)->firstOrFail();
        $declaration = InvestmentDeclaration::where('employee_id', $employee->id)->findOrFail($id);

        if ($declaration->status !== InvestmentDeclaration::STATUS_DRAFT) {
            return response()->json(['message' => "Already {$declaration->status}."], 422);
        }

        $declaration->update(['status' => InvestmentDeclaration::STATUS_SUBMITTED]);

        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => 'submit_investment_declaration',
            'subject_type' => 'InvestmentDeclaration', 'subject_id' => $declaration->id,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        Notifier::push(
            User::payrollAccessUserIds(),
            'investment_declaration',
            'Investment declaration submitted',
            "{$employee->full_name} submitted their investment declaration for FY {$declaration->financial_year}-" . substr((string) ($declaration->financial_year + 1), -2),
            '/payroll-portal/approvals/proof-of-investments',
            ['declaration_id' => $declaration->id, 'employee_id' => $employee->id],
        );

        return response()->json($declaration);
    }

    /** Employee self-service — their own declarations across years. */
    public function mine(Request $request)
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();
        if (! $employee) {
            return response()->json([]);
        }

        return response()->json(
            InvestmentDeclaration::where('employee_id', $employee->id)->orderByDesc('financial_year')->get()
        );
    }

    /** HR review list — a dedicated endpoint, matching the Zoho "Proof of Investments" screen. */
    public function pending(Request $request)
    {
        if (! in_array($request->user()->role, self::REVIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $declarations = InvestmentDeclaration::where('status', InvestmentDeclaration::STATUS_SUBMITTED)
            ->with('employee:id,full_name,employee_code')
            ->orderBy('created_at')
            ->get();

        return response()->json($declarations);
    }

    /** HR view of one employee's declarations across years — the Payroll Portal employee detail page. */
    public function forEmployee(Request $request, $employeeId)
    {
        if (! in_array($request->user()->role, self::REVIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(
            InvestmentDeclaration::where('employee_id', $employeeId)->orderByDesc('financial_year')->get()
        );
    }

    /** HR creates/updates a still-Draft declaration on an employee's behalf. */
    public function storeForEmployee(Request $request, $employeeId)
    {
        if (! in_array($request->user()->role, self::REVIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $employee = Employee::findOrFail($employeeId);

        $validated = $request->validate([
            'financial_year' => 'required|integer|min:2000|max:2100',
            'regime_choice' => 'required|in:old,new',
            'section_80c_amount' => 'nullable|numeric|min:0|max:150000',
            'section_80ccd1b_amount' => 'nullable|numeric|min:0|max:50000',
            'section_80d_amount' => 'nullable|numeric|min:0|max:100000',
            'section_80e_amount' => 'nullable|numeric|min:0',
            'section_80g_amount' => 'nullable|numeric|min:0',
            'section_80tta_amount' => 'nullable|numeric|min:0|max:10000',
            'hra_rent_paid' => 'nullable|numeric|min:0',
            'housing_type' => 'nullable|in:rent,self_occupied_loan',
            'home_loan_interest_declared' => 'nullable|numeric|min:0|max:200000',
            'other_income_declared' => 'nullable|numeric|min:0',
            'previous_employer_salary' => 'nullable|numeric|min:0',
            'lta_exemption_claimed' => 'nullable|numeric|min:0',
            'section_80gg_amount' => 'nullable|numeric|min:0|max:60000',
            'section_80dd_amount' => 'nullable|numeric|min:0|max:125000',
            'section_80ddb_amount' => 'nullable|numeric|min:0|max:100000',
            'section_80ee_amount' => 'nullable|numeric|min:0|max:50000',
            'section_80eea_amount' => 'nullable|numeric|min:0|max:150000',
            'section_80gga_amount' => 'nullable|numeric|min:0',
            'section_80ggc_amount' => 'nullable|numeric|min:0',
            'other_deductions_json' => 'nullable|array',
        ]);

        $declaration = InvestmentDeclaration::where('employee_id', $employee->id)
            ->where('financial_year', $validated['financial_year'])
            ->first();

        if ($declaration && $declaration->status !== InvestmentDeclaration::STATUS_DRAFT) {
            return response()->json(['message' => "This year's declaration is already {$declaration->status} and can no longer be edited."], 422);
        }

        if ($declaration) {
            $declaration->update($validated);
        } else {
            $declaration = InvestmentDeclaration::create([
                ...$validated,
                'employee_id' => $employee->id,
                'status' => InvestmentDeclaration::STATUS_DRAFT,
            ]);
        }

        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => 'update_investment_declaration_on_behalf',
            'subject_type' => 'InvestmentDeclaration', 'subject_id' => $declaration->id,
            'metadata' => ['employee_id' => $employee->id],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($declaration);
    }

    /** HR submits a still-Draft declaration on an employee's behalf (e.g. collected on paper). */
    public function submitForEmployee(Request $request, $employeeId, $id)
    {
        if (! in_array($request->user()->role, self::REVIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $declaration = InvestmentDeclaration::where('employee_id', $employeeId)->findOrFail($id);
        if ($declaration->status !== InvestmentDeclaration::STATUS_DRAFT) {
            return response()->json(['message' => "Already {$declaration->status}."], 422);
        }

        $declaration->update(['status' => InvestmentDeclaration::STATUS_SUBMITTED]);

        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => 'submit_investment_declaration_on_behalf',
            'subject_type' => 'InvestmentDeclaration', 'subject_id' => $declaration->id,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($declaration);
    }
}
