<?php

namespace App\Http\Controllers;

use App\Http\PaginationHelper;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\ReimbursementType;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Employee self-service: submit a reimbursement claim and list your own.
 * Approval itself is NOT handled here — ApprovalController::resolve()'s
 * existing `type: 'Expense'` branch already approves/rejects any ExpenseClaim
 * row regardless of how it was created, so no new approval endpoint is needed.
 * Approved, not-yet-paid claims are swept into the next payroll run by
 * GeneratePayrollJob.
 */
class ReimbursementController extends Controller
{
    private function resolveEmployee(Request $request): ?Employee
    {
        return Employee::where('user_id', $request->user()->id)->first();
    }

    public function store(Request $request)
    {
        $employee = $this->resolveEmployee($request);
        if (! $employee) {
            return response()->json(['message' => 'No employee record linked to your account.'], 403);
        }

        $validated = $request->validate([
            'reimbursement_type_id' => 'required|integer|exists:reimbursement_types,id',
            'amount' => 'required|numeric|min:0.01',
            'claim_date' => 'required|date|before_or_equal:today',
            'description' => 'nullable|string|max:2000',
            'receipt' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $type = ReimbursementType::findOrFail($validated['reimbursement_type_id']);
        if (! $type->active) {
            return response()->json(['message' => "\"{$type->name}\" is no longer an active reimbursement type."], 422);
        }

        if ($type->monthly_cap !== null) {
            $claimMonth = Carbon::parse($validated['claim_date']);
            $alreadyClaimed = ExpenseClaim::where('employee_id', $employee->id)
                ->where('reimbursement_type_id', $type->id)
                ->whereIn('status', ['Pending', 'Approved', 'Paid'])
                ->whereYear('claim_date', $claimMonth->year)
                ->whereMonth('claim_date', $claimMonth->month)
                ->sum('amount');
            if (((float) $alreadyClaimed + (float) $validated['amount']) > (float) $type->monthly_cap) {
                $remaining = max(0, (float) $type->monthly_cap - (float) $alreadyClaimed);
                return response()->json([
                    'message' => "This would exceed {$type->name}'s monthly cap of ₹" . number_format((float) $type->monthly_cap, 2)
                        . ". ₹" . number_format($remaining, 2) . ' remaining this month.',
                ], 422);
            }
        }

        $file = $request->file('receipt');
        $original = preg_replace('/[^\w.\- ()]/', '_', $file->getClientOriginalName());
        $name = $original;
        $i = 1;
        while (Storage::disk('local')->exists("receipts/{$employee->id}/{$name}")) {
            $info = pathinfo($original);
            $name = $info['filename'] . " ({$i})" . (isset($info['extension']) ? ".{$info['extension']}" : '');
            $i++;
        }
        $path = $file->storeAs("receipts/{$employee->id}", $name, 'local');

        $claim = DB::transaction(function () use ($request, $employee, $type, $validated, $path) {
            $claim = ExpenseClaim::create([
                'employee_id' => $employee->id,
                'reimbursement_type_id' => $type->id,
                'category' => $type->name,
                'amount' => $validated['amount'],
                'currency' => 'INR',
                'claim_date' => $validated['claim_date'],
                'description' => $validated['description'] ?? null,
                'receipt_path' => $path,
                'status' => 'Pending',
            ]);

            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'submit_reimbursement',
                'subject_type' => 'ExpenseClaim', 'subject_id' => $claim->id,
                'metadata' => ['type' => $type->name, 'amount' => $validated['amount']],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);

            return $claim;
        });

        Notifier::push(
            User::payrollAccessUserIds(),
            'reimbursement',
            'New reimbursement claim',
            "{$employee->full_name} submitted a {$type->name} claim for ₹" . number_format((float) $validated['amount'], 2),
            '/payroll-portal/approvals/reimbursements',
            ['claim_id' => $claim->id, 'employee_id' => $employee->id],
        );

        return response()->json($claim->load('reimbursementType:id,name'), 201);
    }

    public function mine(Request $request)
    {
        $employee = $this->resolveEmployee($request);
        if (! $employee) {
            return response()->json(['data' => [], 'total' => 0, 'per_page' => 25, 'current_page' => 1, 'last_page' => 1, 'has_more' => false]);
        }

        $query = ExpenseClaim::with(['reimbursementType:id,name', 'payslip:id,payroll_run_id', 'payslip.run:id,period,status'])
            ->where('employee_id', $employee->id)
            ->orderByDesc('claim_date')
            ->orderByDesc('id');

        return response()->json(PaginationHelper::paginate($query, $request));
    }

    public function downloadReceipt(Request $request, $id)
    {
        $claim = ExpenseClaim::findOrFail($id);
        $employee = $this->resolveEmployee($request);
        $isOwner = $employee && (int) $claim->employee_id === (int) $employee->id;
        $isApprover = in_array($request->user()->role, User::PAYROLL_ACCESS_ROLES, true);

        if (! $isOwner && ! $isApprover) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (! $claim->receipt_path || ! Storage::disk('local')->exists($claim->receipt_path)) {
            return response()->json(['message' => 'Receipt not found'], 404);
        }

        return Storage::disk('local')->download($claim->receipt_path, basename($claim->receipt_path));
    }
}
