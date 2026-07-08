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

        if (! in_array($user->role, self::APPROVER_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $perPage = max(1, min((int) $request->query('per_page', 25), 500));
        $page    = max(1, (int) $request->query('page', 1));
        $offset  = ($page - 1) * $perPage;

        // Managers see only direct/dotted-line reports; hr/partner/super_admin see all.
        $scopedEmployeeIds = null;
        if ($user->role === 'manager') {
            $scopedEmployeeIds = Employee::where('reporting_manager_id', $user->id)
                ->orWhere('dotted_line_manager_id', $user->id)
                ->pluck('id')
                ->all();
        }

        $leavesQuery   = LeaveRequest::query();
        $expensesQuery = ExpenseClaim::query();
        if ($scopedEmployeeIds !== null) {
            $leavesQuery->whereIn('employee_id', $scopedEmployeeIds);
            $expensesQuery->whereIn('employee_id', $scopedEmployeeIds);
        }

        // Client approvals: managers track only their own requests; admins/partners/hr see all.
        $clientApprovalsQuery = Approval::where('type', 'client');
        if ($user->role === 'manager') {
            $clientApprovalsQuery->where('requester_id', $user->id);
        }

        $leavesCount   = (clone $leavesQuery)->count();
        $expensesCount = (clone $expensesQuery)->count();
        $clientCount   = (clone $clientApprovalsQuery)->count();
        $total         = $leavesCount + $expensesCount + $clientCount;

        // DB-level UNION pagination — only fetches the current page rows
        $leaveBase    = DB::table('leave_requests')->select('id', DB::raw("'Leave' as type"), 'created_at');
        $expenseBase  = DB::table('expense_claims')->select('id', DB::raw("'Expense' as type"), 'created_at');
        $approvalBase = DB::table('approvals')->select('id', DB::raw("'Client' as type"), 'created_at')->where('type', 'client');
        if ($scopedEmployeeIds !== null) {
            $leaveBase->whereIn('employee_id', $scopedEmployeeIds ?: [-1]);
            $expenseBase->whereIn('employee_id', $scopedEmployeeIds ?: [-1]);
        }
        if ($user->role === 'manager') {
            $approvalBase->where('requester_id', $user->id);
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

        $clientApprovals = isset($idsByType['Client'])
            ? Approval::with('requester:id,name', 'client:id,company_name')->whereIn('id', $idsByType['Client'])->get()->keyBy('id')
            : collect();

        $data = $unionPage->map(function ($item) use ($leaves, $expenses, $clientApprovals) {
            if ($item->type === 'Client') {
                $a = $clientApprovals->get($item->id);
                if (! $a) return null;
                return [
                    'id'          => $a->id,
                    'type'        => 'Client',
                    'requester'   => $a->requester?->name ?? '—',
                    'title'       => $a->title,
                    'description' => "For {$a->client?->company_name}: {$a->title}" . ($a->description ? " — {$a->description}" : ''),
                    'amount'      => null,
                    'from_date'   => null,
                    'to_date'     => null,
                    'submitted'   => $a->created_at?->toDateString(),
                    'status'      => strtolower($a->status),
                    'urgency'     => 'Normal',
                    'comments'    => $a->comments,
                    'can_resolve' => false, // firm side only tracks; the client_admin resolves
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
     * Create a client approval request (firm side → client_admin action).
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, self::CLIENT_APPROVAL_CREATORS)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'client_id'   => 'required|integer|exists:clients,id',
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
        ]);

        $approval = Approval::create([
            'requester_id' => $user->id,
            'approver_id'  => $user->id, // updated to the resolving client_admin on action
            'client_id'    => $validated['client_id'],
            'type'         => 'client',
            'title'        => $validated['title'],
            'description'  => $validated['description'] ?? null,
            'subject_type' => 'Client',
            'subject_id'   => $validated['client_id'],
            'status'       => 'Pending',
        ]);

        // Notify every portal user of this client
        $client = Client::find($validated['client_id']);
        $portalUserIds = collect($client?->portalUserIds() ?? []);
        $now = now();
        $notifications = $portalUserIds->map(fn ($uid) => [
            'user_id'     => $uid,
            'type'        => 'approval',
            'title'       => 'Approval requested',
            'description' => "{$user->name} requested your approval: {$validated['title']}",
            'meta'        => json_encode(['approval_id' => $approval->id]),
            'action_url'  => '/approvals',
            'created_at'  => $now,
            'updated_at'  => $now,
        ])->all();
        if ($notifications) {
            DB::table('ip_notifications')->insert($notifications);
        }

        AuditLog::create([
            'user_id'      => $user->id,
            'action'       => 'create_client_approval',
            'subject_type' => 'Approval',
            'subject_id'   => $approval->id,
            'metadata'     => ['client_id' => $validated['client_id'], 'title' => $validated['title']],
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json($approval, 201);
    }

    public function resolve(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'type'    => 'required|in:Leave,Expense,Client',
            'id'      => 'required|integer',
            'action'  => 'required|in:Approved,Rejected',
            'comment' => 'nullable|string|max:2000',
        ]);

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
            DB::table('ip_notifications')->insert([
                'user_id'     => $approval->requester_id,
                'type'        => 'approval',
                'title'       => "Client approval {$validated['action']}",
                'description' => "{$user->name} ({$ownClient->company_name}) {$validated['action']}: {$approval->title}",
                'meta'        => json_encode(['approval_id' => $approval->id]),
                'action_url'  => '/approvals',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

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
