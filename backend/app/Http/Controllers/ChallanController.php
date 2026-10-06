<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Challan;
use App\Models\ChallanAllocation;
use App\Models\TdsLiability;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Government TDS tax payments and their association against open
 * TdsLiability records — see /taxes-and-forms/challans.html. One challan can
 * be split across many employees' liabilities (ChallanAllocation is a ledger,
 * same shape as LoanRepayment, so a dissociate is just "delete the row and
 * recompute" rather than requiring signed reversal entries).
 */
class ChallanController extends Controller
{
    private const MANAGE_ROLES = User::PAYROLL_ACCESS_ROLES;

    public function index(Request $request)
    {
        if (! in_array($request->user()->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $query = Challan::with('allocations.tdsLiability.employee:id,full_name,employee_code', 'recordedBy:id,name');

        if ($request->filled('financial_year')) {
            $query->where('financial_year', $request->query('financial_year'));
        }
        if ($request->filled('quarter')) {
            $query->where('quarter', $request->query('quarter'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return response()->json($query->orderByDesc('payment_date')->get());
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'bsr_code' => 'required|string|max:7',
            'payment_date' => 'required|date',
            'serial_number' => 'required|string|max:10',
            'amount' => 'required|numeric|min:0.01',
            'interest_amount' => 'nullable|numeric|min:0',
            'penalty_amount' => 'nullable|numeric|min:0',
            'financial_year' => 'required|integer|min:2000|max:2100',
            'quarter' => 'nullable|integer|min:1|max:4',
            'notes' => 'nullable|string|max:1000',
        ]);

        $challan = Challan::create([
            ...$validated,
            'interest_amount' => $validated['interest_amount'] ?? 0,
            'penalty_amount' => $validated['penalty_amount'] ?? 0,
            'status' => Challan::STATUS_UNASSOCIATED,
            'recorded_by_id' => $user->id,
        ]);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'record_challan',
            'subject_type' => 'Challan', 'subject_id' => $challan->id,
            'metadata' => ['amount' => (float) $challan->amount, 'bsr_code' => $challan->bsr_code],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($challan, 201);
    }

    /** Associates (partially or fully) specific rupee amounts from this challan against specific employees' open liabilities. */
    public function associate(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'allocations' => 'required|array|min:1',
            'allocations.*.tds_liability_id' => 'required|exists:tds_liabilities,id',
            'allocations.*.amount' => 'required|numeric|min:0.01',
        ]);

        $challan = DB::transaction(function () use ($id, $validated, $user) {
            $challan = Challan::lockForUpdate()->findOrFail($id);

            $requestedTotal = round(array_sum(array_column($validated['allocations'], 'amount')), 2);
            if ($requestedTotal > $challan->unallocatedAmount() + 0.01) {
                abort(422, 'Allocation total exceeds this challan\'s unallocated amount.');
            }

            foreach ($validated['allocations'] as $row) {
                $liability = TdsLiability::lockForUpdate()->findOrFail($row['tds_liability_id']);
                if ($row['amount'] > $liability->outstandingAmount() + 0.01) {
                    abort(422, "Allocation of ₹{$row['amount']} exceeds liability #{$liability->id}'s outstanding amount of ₹{$liability->outstandingAmount()}.");
                }

                ChallanAllocation::create([
                    'challan_id' => $challan->id,
                    'tds_liability_id' => $liability->id,
                    'amount' => $row['amount'],
                    'associated_by_id' => $user->id,
                ]);

                $newPaid = round((float) $liability->tds_paid + $row['amount'], 2);
                $liability->update([
                    'tds_paid' => $newPaid,
                    'status' => $newPaid >= (float) $liability->tds_deducted - 0.01
                        ? TdsLiability::STATUS_PAID
                        : TdsLiability::STATUS_PARTIALLY_PAID,
                ]);
            }

            $challan->refresh();
            $challan->update([
                'status' => $challan->unallocatedAmount() <= 0.01
                    ? Challan::STATUS_ASSOCIATED
                    : Challan::STATUS_PARTIALLY_ASSOCIATED,
            ]);

            return $challan;
        });

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'associate_challan',
            'subject_type' => 'Challan', 'subject_id' => $challan->id,
            'metadata' => ['allocations' => $validated['allocations']],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($challan->load('allocations.tdsLiability.employee:id,full_name,employee_code'));
    }

    public function dissociate(Request $request, $allocationId)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        DB::transaction(function () use ($allocationId) {
            $allocation = ChallanAllocation::lockForUpdate()->findOrFail($allocationId);
            $liability = TdsLiability::lockForUpdate()->findOrFail($allocation->tds_liability_id);
            $challan = Challan::lockForUpdate()->findOrFail($allocation->challan_id);

            $newPaid = max(0.0, round((float) $liability->tds_paid - (float) $allocation->amount, 2));
            $liability->update([
                'tds_paid' => $newPaid,
                'status' => $newPaid <= 0
                    ? TdsLiability::STATUS_OPEN
                    : ($newPaid >= (float) $liability->tds_deducted - 0.01 ? TdsLiability::STATUS_PAID : TdsLiability::STATUS_PARTIALLY_PAID),
            ]);

            $allocation->delete();

            $challan->refresh();
            $challan->update([
                'status' => $challan->allocatedTotal() <= 0
                    ? Challan::STATUS_UNASSOCIATED
                    : ($challan->unallocatedAmount() <= 0.01 ? Challan::STATUS_ASSOCIATED : Challan::STATUS_PARTIALLY_ASSOCIATED),
            ]);
        });

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'dissociate_challan_allocation',
            'subject_type' => 'ChallanAllocation', 'subject_id' => (int) $allocationId,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }
}
