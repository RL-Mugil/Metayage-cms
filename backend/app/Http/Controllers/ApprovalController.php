<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ExpenseClaim;
use App\Models\LeaveRequest;
use App\Services\LeaveApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApprovalController extends Controller
{
    private const APPROVER_ROLES = ['super_admin', 'hr', 'manager', 'partner'];

    public function index(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, self::APPROVER_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $perPage = max(1, min((int) $request->query('per_page', 25), 500));
        $page    = max(1, (int) $request->query('page', 1));
        $offset  = ($page - 1) * $perPage;

        $leavesCount   = LeaveRequest::count();
        $expensesCount = ExpenseClaim::count();
        $total         = $leavesCount + $expensesCount;

        // DB-level UNION pagination — only fetches the current page rows
        $unionPage = DB::table('leave_requests')
            ->select('id', DB::raw("'Leave' as type"), 'created_at')
            ->unionAll(
                DB::table('expense_claims')
                    ->select('id', DB::raw("'Expense' as type"), 'created_at')
            )
            ->orderBy('created_at', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $idsByType = $unionPage->groupBy('type')->map(fn($g) => $g->pluck('id'));

        $leaves = isset($idsByType['Leave'])
            ? LeaveRequest::with('employee:id,full_name')->whereIn('id', $idsByType['Leave'])->get()->keyBy('id')
            : collect();

        $expenses = isset($idsByType['Expense'])
            ? ExpenseClaim::with('employee:id,full_name')->whereIn('id', $idsByType['Expense'])->get()->keyBy('id')
            : collect();

        $data = $unionPage->map(function ($item) use ($leaves, $expenses) {
            if ($item->type === 'Leave') {
                $l = $leaves->get($item->id);
                if (! $l) return null;
                return [
                    'id'          => $l->id,
                    'type'        => 'Leave',
                    'requester'   => $l->employee?->full_name ?? '—',
                    'description' => "{$l->leave_type} leave — " . ($l->reason ?: 'no reason given'),
                    'amount'      => null,
                    'from_date'   => $l->from_date,
                    'to_date'     => $l->to_date,
                    'submitted'   => $l->created_at?->toDateString(),
                    'status'      => strtolower($l->status === 'Cancelled' ? 'Rejected' : $l->status),
                    'urgency'     => ((float) $l->total_days) > 5 ? 'High' : 'Normal',
                    'created_at'  => $l->created_at,
                ];
            }

            $e = $expenses->get($item->id);
            if (! $e) return null;
            return [
                'id'          => $e->id,
                'type'        => 'Expense',
                'requester'   => $e->employee?->full_name ?? '—',
                'description' => "{$e->category} — " . ($e->description ?: 'no description'),
                'amount'      => "{$e->currency} {$e->amount}",
                'from_date'   => null,
                'to_date'     => null,
                'submitted'   => $e->created_at?->toDateString(),
                'status'      => strtolower($e->status === 'Paid' ? 'Approved' : $e->status),
                'urgency'     => ((float) $e->amount) > 50000 ? 'High' : 'Normal',
                'created_at'  => $e->created_at,
            ];
        })->filter()->values();

        return response()->json([
            'data'         => $data,
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => (int) ceil($total / max($perPage, 1)),
            'has_more'     => ($page * $perPage) < $total,
        ]);
    }

    public function resolve(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, self::APPROVER_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'type'   => 'required|in:Leave,Expense',
            'id'     => 'required|integer',
            'action' => 'required|in:Approved,Rejected',
        ]);

        if ($validated['type'] === 'Leave') {
            $leave = LeaveRequest::findOrFail($validated['id']);
            app(LeaveApprovalService::class)->resolve($leave, $validated['action'], $user->id);
            $subjectType = 'LeaveRequest';
        } else {
            $claim = ExpenseClaim::findOrFail($validated['id']);
            if ($claim->status !== 'Pending') {
                return response()->json(['message' => "Claim already {$claim->status}."], 422);
            }
            $claim->update(['status' => $validated['action'], 'approved_by_id' => $user->id]);
            $subjectType = 'ExpenseClaim';
        }

        AuditLog::create([
            'user_id'      => $user->id,
            'action'       => 'resolve_approval',
            'subject_type' => $subjectType,
            'subject_id'   => $validated['id'],
            'metadata'     => ['action' => $validated['action']],
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }
}
