<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ExpenseClaim;
use App\Models\LeaveRequest;
use App\Services\LeaveApprovalService;
use App\Http\PaginationHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ApprovalController extends Controller
{
    private const APPROVER_ROLES = ['super_admin', 'hr', 'manager', 'partner'];

    public function index(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, self::APPROVER_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $leaves = LeaveRequest::with('employee:id,full_name')->get()
            ->map(fn ($l) => [
                'id'        => $l->id,
                'type'      => 'Leave',
                'requester' => $l->employee?->full_name ?? '—',
                'description' => "{$l->leave_type} leave — " . ($l->reason ?: 'no reason given'),
                'amount'    => null,
                'from_date' => $l->from_date,
                'to_date'   => $l->to_date,
                'submitted' => $l->created_at?->toDateString(),
                'status'    => strtolower($l->status === 'Cancelled' ? 'Rejected' : $l->status),
                'urgency'   => ((float) $l->total_days) > 5 ? 'High' : 'Normal',
                'created_at' => $l->created_at,
            ]);

        $expenses = ExpenseClaim::with('employee:id,full_name')->get()
            ->map(fn ($e) => [
                'id'        => $e->id,
                'type'      => 'Expense',
                'requester' => $e->employee?->full_name ?? '—',
                'description' => "{$e->category} — " . ($e->description ?: 'no description'),
                'amount'    => "{$e->currency} {$e->amount}",
                'from_date' => null,
                'to_date'   => null,
                'submitted' => $e->created_at?->toDateString(),
                'status'    => strtolower($e->status === 'Paid' ? 'Approved' : $e->status),
                'urgency'   => ((float) $e->amount) > 50000 ? 'High' : 'Normal',
                'created_at' => $e->created_at,
            ]);

        $allApprovals = $leaves->concat($expenses)->sortByDesc('created_at')->values();

        $perPage = (int) $request->query('per_page', 25);
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min($perPage, 500));

        $total = $allApprovals->count();
        $lastPage = (int) ceil($total / $perPage);
        $data = $allApprovals->slice(($page - 1) * $perPage, $perPage)->values();
        $hasMore = ($page * $perPage) < $total;

        return response()->json([
            'data' => $data,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $lastPage,
            'has_more' => $hasMore,
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
            'user_id' => $user->id, 'action' => 'resolve_approval',
            'subject_type' => $subjectType, 'subject_id' => $validated['id'],
            'metadata' => ['action' => $validated['action']],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }
}
