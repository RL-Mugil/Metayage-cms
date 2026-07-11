<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveApprovalService;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApprovalController extends Controller
{
    private const APPROVER_ROLES = ['super_admin', 'hr', 'manager', 'partner'];
    private const CLIENT_APPROVAL_CREATORS = ['super_admin', 'partner', 'manager'];

    /** List approvals for a client portal user (their own client only). */
    private function clientIndex(Request $request)
    {
        $user   = $request->user();
        $client = $request->attributes->get('portal_client') ?? Client::forUser($user);
        if (! $client) {
            return response()->json(['message' => 'No client record linked to your account.'], 403);
        }

        $perPage   = max(1, min((int) $request->query('per_page', 25), 500));
        $paginated = Approval::with('requester:id,name')
            ->where('client_id', $client->id)
            ->where('type', 'client')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $canResolve = $user->role === 'client_admin';

        return response()->json([
            'data' => collect($paginated->items())->map(fn ($a) => [
                'id'          => $a->id,
                'type'        => 'Client',
                'requester'   => $a->requester?->name ?? '—',
                'title'       => $a->title,
                'description' => $a->title . ($a->description ? " — {$a->description}" : ''),
                'amount'      => null,
                'from_date'   => null,
                'to_date'     => null,
                'submitted'   => $a->created_at?->toDateString(),
                'status'      => strtolower($a->status),
                'urgency'     => 'Normal',
                'comments'    => $a->comments,
                'can_resolve' => $canResolve && $a->status === 'Pending',
                'created_at'  => $a->created_at,
            ]),
            'total'        => $paginated->total(),
            'per_page'     => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'has_more'     => $paginated->hasMorePages(),
        ]);
    }

    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->isClientRole()) {
            return $this->clientIndex($request);
        }

        // All internal staff may view the approvals page. Leave/expense visibility
        // stays restricted to HR approvers (separation of duties); client &
        // colleague approvals are visible to the people involved, plus
        // admins/partners/hr for oversight.
        $canSeeHrApprovals = in_array($user->role, self::APPROVER_ROLES);
        $seesAllApprovals  = in_array($user->role, ['super_admin', 'partner', 'hr']);

        $perPage = max(1, min((int) $request->query('per_page', 25), 500));
        $page    = max(1, (int) $request->query('page', 1));
        $offset  = ($page - 1) * $perPage;

        // Leave/expense scoping: non-approvers see none; managers see their
        // reports; hr/partner/super_admin see all.
        $scopedEmployeeIds = null; // null = all
        if (! $canSeeHrApprovals) {
            $scopedEmployeeIds = [-1]; // none
        } elseif ($user->role === 'manager') {
            $scopedEmployeeIds = Employee::where('reporting_manager_id', $user->id)
                ->orWhere('dotted_line_manager_id', $user->id)
                ->pluck('id')
                ->all() ?: [-1];
        }

        $leavesQuery   = LeaveRequest::query();
        $expensesQuery = ExpenseClaim::query();
        if ($scopedEmployeeIds !== null) {
            $leavesQuery->whereIn('employee_id', $scopedEmployeeIds);
            $expensesQuery->whereIn('employee_id', $scopedEmployeeIds);
        }

        // Client + colleague approvals from the approvals table. Everyone sees
        // the ones they raised or are addressed to; oversight roles see all.
        $applyApprovalScope = function ($q) use ($user, $seesAllApprovals) {
            $q->whereIn('type', ['client', 'colleague']);
            if (! $seesAllApprovals) {
                $q->where(function ($w) use ($user) {
                    $w->where('requester_id', $user->id)
                      ->orWhere('approver_id', $user->id);
                });
            }
        };

        $leavesCount   = (clone $leavesQuery)->count();
        $expensesCount = (clone $expensesQuery)->count();
        $approvalCount = Approval::where($applyApprovalScope)->count();
        $total         = $leavesCount + $expensesCount + $approvalCount;

        // DB-level UNION pagination — only fetches the current page rows
        $leaveBase    = DB::table('leave_requests')->select('id', DB::raw("'Leave' as type"), 'created_at');
        $expenseBase  = DB::table('expense_claims')->select('id', DB::raw("'Expense' as type"), 'created_at');
        $approvalBase = DB::table('approvals')->select('id', DB::raw("'Approval' as type"), 'created_at')
            ->whereIn('type', ['client', 'colleague']);
        if ($scopedEmployeeIds !== null) {
            $leaveBase->whereIn('employee_id', $scopedEmployeeIds);
            $expenseBase->whereIn('employee_id', $scopedEmployeeIds);
        }
        if (! $seesAllApprovals) {
            $approvalBase->where(function ($w) use ($user) {
                $w->where('requester_id', $user->id)
                  ->orWhere('approver_id', $user->id);
            });
        }
        $unionPage = $leaveBase
            ->unionAll($expenseBase)
            ->unionAll($approvalBase)
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

        $approvals = isset($idsByType['Approval'])
            ? Approval::with('requester:id,name', 'approver:id,name', 'client:id,company_name')->whereIn('id', $idsByType['Approval'])->get()->keyBy('id')
            : collect();

        $data = $unionPage->map(function ($item) use ($leaves, $expenses, $approvals, $user) {
            if ($item->type === 'Approval') {
                $a = $approvals->get($item->id);
                if (! $a) return null;
                $isColleague = $a->type === 'colleague';
                $recipient   = $isColleague
                    ? ($a->approver?->name ?? '—')
                    : ($a->client?->company_name ?? '—');
                // Only the addressed colleague may resolve a pending colleague approval.
                $canResolve = $isColleague
                    && $a->status === 'Pending'
                    && (int) $a->approver_id === (int) $user->id;
                return [
                    'id'          => $a->id,
                    'type'        => $isColleague ? 'Colleague' : 'Client',
                    'requester'   => $a->requester?->name ?? '—',
                    'title'       => $a->title,
                    'description' => ($isColleague ? "To {$recipient}: " : "For {$recipient}: ")
                        . $a->title . ($a->description ? " — {$a->description}" : ''),
                    'amount'      => null,
                    'from_date'   => null,
                    'to_date'     => null,
                    'submitted'   => $a->created_at?->toDateString(),
                    'status'      => strtolower($a->status),
                    'urgency'     => 'Normal',
                    'comments'    => $a->comments,
                    'can_resolve' => $canResolve,
                    'created_at'  => $a->created_at,
                ];
            }
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

    /**
     * Raise an approval and send it to a client (portal admin acts) or a
     * colleague (the addressed internal user acts). Any internal staff may raise.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if ($user->isClientRole()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'client_id'   => 'nullable|integer|exists:clients,id',
            'approver_id' => 'nullable|integer|exists:users,id',
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
        ]);

        if (empty($validated['client_id']) && empty($validated['approver_id'])) {
            return response()->json(['message' => 'Choose a client or a colleague to send this approval to.'], 422);
        }

        $isColleague = ! empty($validated['approver_id']);
        if ($isColleague && (int) $validated['approver_id'] === (int) $user->id) {
            return response()->json(['message' => 'You cannot send an approval to yourself.'], 422);
        }

        $approval = Approval::create([
            'requester_id' => $user->id,
            'approver_id'  => $isColleague ? $validated['approver_id'] : $user->id, // client flow updates approver_id on resolve
            'client_id'    => $isColleague ? null : $validated['client_id'],
            'type'         => $isColleague ? 'colleague' : 'client',
            'title'        => $validated['title'],
            'description'  => $validated['description'] ?? null,
            'subject_type' => $isColleague ? 'User' : 'Client',
            'subject_id'   => $isColleague ? $validated['approver_id'] : $validated['client_id'],
            'status'       => 'Pending',
        ]);

        if ($isColleague) {
            Notifier::push(
                $validated['approver_id'],
                'approval',
                'Approval requested',
                "{$user->name} requested your approval: {$validated['title']}",
                '/approvals',
                ['approval_id' => $approval->id],
            );
        } else {
            $client = Client::find($validated['client_id']);
            Notifier::push(
                collect($client?->portalUserIds() ?? [])->all(),
                'approval',
                'Approval requested',
                "{$user->name} requested your approval: {$validated['title']}",
                '/approvals',
                ['approval_id' => $approval->id],
            );
        }

        AuditLog::create([
            'user_id'      => $user->id,
            'action'       => $isColleague ? 'create_colleague_approval' : 'create_client_approval',
            'subject_type' => 'Approval',
            'subject_id'   => $approval->id,
            'metadata'     => [
                'recipient' => $isColleague ? "user:{$validated['approver_id']}" : "client:{$validated['client_id']}",
                'title'     => $validated['title'],
            ],
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json($approval, 201);
    }

    public function resolve(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'type'    => 'required|in:Leave,Expense,Client,Colleague',
            'id'      => 'required|integer',
            'action'  => 'required|in:Approved,Rejected',
            'comment' => 'nullable|string|max:2000',
        ]);

        // ── Colleague approvals: only the addressed internal user may act ──
        if ($validated['type'] === 'Colleague') {
            $approval = Approval::findOrFail($validated['id']);
            if ($approval->type !== 'colleague' || (int) $approval->approver_id !== (int) $user->id) {
                return response()->json(['message' => 'Only the addressed colleague can approve or reject.'], 403);
            }
            if ($approval->status !== 'Pending') {
                return response()->json(['message' => "Already {$approval->status}."], 422);
            }

            $approval->update([
                'status'   => $validated['action'],
                'comments' => $validated['comment'] ?? null,
            ]);

            Notifier::push(
                $approval->requester_id,
                'approval',
                "Approval {$validated['action']}",
                "{$user->name} {$validated['action']}: {$approval->title}"
                    . ($validated['comment'] ? " — {$validated['comment']}" : ''),
                '/approvals',
                ['approval_id' => $approval->id],
            );

            AuditLog::create([
                'user_id'      => $user->id,
                'action'       => 'resolve_approval',
                'subject_type' => 'Approval',
                'subject_id'   => $approval->id,
                'metadata'     => ['action' => $validated['action'], 'kind' => 'colleague'],
                'ip_address'   => $request->ip(),
                'user_agent'   => $request->userAgent(),
            ]);

            return response()->json(['ok' => true]);
        }

        // ── Client approvals: only the client_admin of that client may act ──
        if ($validated['type'] === 'Client') {
            if ($user->role !== 'client_admin') {
                return response()->json(['message' => 'Only your portal admin can approve or reject.'], 403);
            }
            $ownClient = $request->attributes->get('portal_client') ?? Client::forUser($user);
            $approval  = Approval::findOrFail($validated['id']);
            if (! $ownClient || (int) $approval->client_id !== (int) $ownClient->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            if ($approval->status !== 'Pending') {
                return response()->json(['message' => "Already {$approval->status}."], 422);
            }

            $approval->update([
                'status'      => $validated['action'],
                'approver_id' => $user->id,
                'comments'    => $validated['comment'] ?? null,
            ]);

            // Notify the firm-side requester
            Notifier::push(
                $approval->requester_id,
                'approval',
                "Client approval {$validated['action']}",
                "{$user->name} ({$ownClient->company_name}) {$validated['action']}: {$approval->title}",
                '/approvals',
                ['approval_id' => $approval->id],
            );

            AuditLog::create([
                'user_id'      => $user->id,
                'action'       => 'resolve_approval',
                'subject_type' => 'Approval',
                'subject_id'   => $approval->id,
                'metadata'     => ['action' => $validated['action']],
                'ip_address'   => $request->ip(),
                'user_agent'   => $request->userAgent(),
            ]);

            return response()->json(['ok' => true]);
        }

        if (! in_array($user->role, self::APPROVER_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($validated['type'] === 'Leave') {
            $leave = LeaveRequest::findOrFail($validated['id']);
            if ($user->role === 'manager') {
                $allowed = Employee::where('reporting_manager_id', $user->id)
                    ->orWhere('dotted_line_manager_id', $user->id)
                    ->pluck('id')->all();
                if (! in_array($leave->employee_id, $allowed)) {
                    return response()->json(['message' => 'Forbidden'], 403);
                }
            }
            app(LeaveApprovalService::class)->resolve($leave, $validated['action'], $user->id);
            $subjectType = 'LeaveRequest';
        } else {
            $claim = ExpenseClaim::findOrFail($validated['id']);
            if ($user->role === 'manager') {
                $allowed = Employee::where('reporting_manager_id', $user->id)
                    ->orWhere('dotted_line_manager_id', $user->id)
                    ->pluck('id')->all();
                if (! in_array($claim->employee_id, $allowed)) {
                    return response()->json(['message' => 'Forbidden'], 403);
                }
            }
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
