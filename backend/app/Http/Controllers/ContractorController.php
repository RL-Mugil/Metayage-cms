<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Contractor;
use App\Models\ContractorPayment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Invoice-driven contractor payments (Section 194J/194C withholding) — kept
 * deliberately separate from Employee/Payslip since contractors have no
 * department, attendance, leave balance, PF/ESI, or monthly payslip concept.
 */
class ContractorController extends Controller
{
    private const MANAGE_ROLES = User::PAYROLL_ACCESS_ROLES;
    private const VIEW_ROLES = User::PAYROLL_ACCESS_ROLES;

    public function index(Request $request)
    {
        if (! in_array($request->user()->role, self::VIEW_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(Contractor::with('payments')->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'pan_tax_id' => 'nullable|regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/',
            'gstin' => 'nullable|string|max:20',
            'bank_account_number' => 'nullable|string|max:50',
            'bank_name' => 'nullable|string|max:255',
            'bank_ifsc_code' => 'nullable|regex:/^[A-Z]{4}0[A-Z0-9]{6}$/',
            'payment_terms' => 'nullable|string|max:255',
            'tds_section' => 'nullable|in:194J,194C',
        ]);

        $contractor = DB::transaction(function () use ($validated) {
            $year = date('Y');
            $last = Contractor::where('contractor_code', 'like', "CTR-{$year}-%")
                ->orderBy('contractor_code', 'desc')
                ->lockForUpdate()
                ->value('contractor_code');
            $next = $last ? ((int) substr($last, -4)) + 1 : 1;
            $code = sprintf('CTR-%s-%04d', $year, $next);

            return Contractor::create([
                ...$validated,
                'contractor_code' => $code,
                'tds_section' => $validated['tds_section'] ?? '194J',
                'status' => 'Active',
            ]);
        });

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'create_contractor',
            'subject_type' => 'Contractor', 'subject_id' => $contractor->id,
            'metadata' => ['contractor_code' => $contractor->contractor_code],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($contractor, 201);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $contractor = Contractor::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'pan_tax_id' => 'nullable|regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/',
            'gstin' => 'nullable|string|max:20',
            'bank_account_number' => 'nullable|string|max:50',
            'bank_name' => 'nullable|string|max:255',
            'bank_ifsc_code' => 'nullable|regex:/^[A-Z]{4}0[A-Z0-9]{6}$/',
            'payment_terms' => 'nullable|string|max:255',
            'tds_section' => 'nullable|in:194J,194C',
            'status' => 'nullable|in:Active,Inactive',
        ]);

        $contractor->update($validated);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'update_contractor',
            'subject_type' => 'Contractor', 'subject_id' => $contractor->id,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($contractor);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $contractor = Contractor::findOrFail($id);
        if ($contractor->payments()->exists()) {
            $contractor->update(['status' => 'Inactive']);
        } else {
            $contractor->delete();
        }

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'delete_contractor',
            'subject_type' => 'Contractor', 'subject_id' => (int) $id,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function recordPayment(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $contractor = Contractor::findOrFail($id);

        $validated = $request->validate([
            'invoice_number' => 'required|string|max:100',
            'invoice_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'tds_deducted' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:Pending,Paid',
        ]);

        $tds = (float) ($validated['tds_deducted'] ?? 0);
        $netPaid = round((float) $validated['amount'] - $tds, 2);
        $status = $validated['status'] ?? 'Pending';

        $payment = DB::transaction(function () use ($contractor, $validated, $tds, $netPaid, $status) {
            return ContractorPayment::create([
                'contractor_id' => $contractor->id,
                'invoice_number' => $validated['invoice_number'],
                'invoice_date' => $validated['invoice_date'],
                'amount' => $validated['amount'],
                'tds_deducted' => $tds,
                'net_paid' => $netPaid,
                'status' => $status,
                'paid_at' => $status === 'Paid' ? now() : null,
                'recorded_by_id' => auth()->id(),
            ]);
        });

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'record_contractor_payment',
            'subject_type' => 'ContractorPayment', 'subject_id' => $payment->id,
            'metadata' => ['contractor_id' => $contractor->id, 'amount' => (float) $payment->amount],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($payment->load('contractor:id,name,contractor_code'), 201);
    }
}
