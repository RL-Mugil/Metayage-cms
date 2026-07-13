<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Http\PaginationHelper;
use App\Services\LeaveBalanceService;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LeaveController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if ($user->isClientRole()) return response()->json(['message' => 'Forbidden'], 403);

        $employee   = Employee::where('user_id', $user->id)->first();
        $isApprover = in_array($user->role, User::LEAVE_APPROVER_ROLES, true);

        $query = LeaveRequest::with(['employee:id,full_name,user_id', 'employee.user:id,avatar_url'])->orderBy('from_date', 'desc');
        if (! $isApprover) {
            if (! $employee) return response()->json(['requests' => [], 'balances' => null, 'is_approver' => false]);
            $query->where('employee_id', $employee->id);
        }

        $perPage = min((int) $request->query('per_page', 200), 500);
        $page    = max(1, (int) $request->query('page', 1));
        $total   = $query->count();
        $items   = $query->forPage($page, $perPage)->get()->map(fn ($r) => [
            'id'            => $r->id,
            'employee_id'   => $r->employee_id,
            'employee_name'   => $r->employee?->full_name,
            'employee_avatar' => $r->employee?->user?->avatar_url,
            'leave_type'    => $r->leave_type,
            'from_date'     => $r->from_date,
            'to_date'       => $r->to_date,
            'total_days'    => (float) $r->total_days,
            'reason'        => $r->reason,
            'status'        => $r->status,
            'comments'      => $r->comments,
            'is_mine'       => $employee ? $r->employee_id === $employee->id : false,
        ]);

        $balanceService = app(LeaveBalanceService::class);
        $balances = $employee ? $balanceService->currentYearBalance($employee) : null;

        return response()->json([
            'requests'       => $items,
            'total'          => $total,
            'per_page'       => $perPage,
            'current_page'   => $page,
            'last_page'      => (int) ceil($total / $perPage),
            'balances'       => $balances,
            'entitlements'   => $balanceService->entitlements(),
            'is_approver'    => $isApprover,
        ]);
    }

    public function store(Request $request)
    {
        $user     = $request->user();
        $employee = Employee::where('user_id', $user->id)->first();

        if (! $employee) return response()->json(['message' => 'No employee profile linked to your account.'], 422);

        app(LeaveBalanceService::class)->currentYearBalance($employee);

        $request->validate([
            'leave_type' => 'required|string',
            'from_date'  => 'required|date',
            'to_date'    => 'required|date|after_or_equal:from_date',
            'reason'     => 'required|string',
        ]);

        $overlap = LeaveRequest::where('employee_id', $employee->id)
            ->whereNotIn('status', ['Rejected', 'Cancelled'])
            ->where(function ($q) use ($request) {
                $q->whereBetween('from_date', [$request->from_date, $request->to_date])
                  ->orWhereBetween('to_date', [$request->from_date, $request->to_date])
                  ->orWhere(function ($q2) use ($request) {
                      $q2->where('from_date', '<=', $request->from_date)
                         ->where('to_date', '>=', $request->to_date);
                  });
            })
            ->exists();

        if ($overlap) {
            return response()->json(['message' => 'Leave dates overlap with an existing request.'], 422);
        }

        $totalDays = $this->countBusinessDays(
            Carbon::parse($request->from_date),
            Carbon::parse($request->to_date)
        );

        if ($totalDays === 0) {
            return response()->json(['message' => 'Selected dates fall entirely on weekends or public holidays.'], 422);
        }

        $leaveReq = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type'  => $request->leave_type,
            'from_date'   => $request->from_date,
            'to_date'     => $request->to_date,
            'total_days'  => $totalDays,
            'reason'      => $request->reason,
            'status'      => 'Pending',
        ]);

        AuditLog::create([
            'user_id'      => $user->id,
            'action'       => 'apply_leave',
            'subject_type' => 'LeaveRequest',
            'subject_id'   => $leaveReq->id,
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        $recipientIds = User::where('status', 'Active')
            ->where(function ($query) {
                $query->whereIn('role', User::LEAVE_APPROVER_ROLES)
                    ->orWhere('role', 'galvanizer');
            })
            ->where('id', '!=', $user->id)
            ->pluck('id')
            ->all();

        Notifier::push(
            $recipientIds,
            'leave',
            'Leave request submitted',
            "{$employee->full_name} requested {$leaveReq->total_days} day(s) of {$leaveReq->leave_type}.",
            '/hrms/leave',
            ['leave_request_id' => $leaveReq->id],
        );

        return response()->json($leaveReq, 201);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        $this->authorize('approveLeave', \App\Models\Employee::class);

        $leave = LeaveRequest::findOrFail($id);

        $request->validate(['status' => 'required|in:Approved,Rejected,Cancelled']);

        $leave = app(\App\Services\LeaveApprovalService::class)
            ->resolve($leave, $request->status, $user->id, $request->comments);

        AuditLog::create([
            'user_id'      => $user->id,
            'action'       => 'resolve_leave',
            'subject_type' => 'LeaveRequest',
            'subject_id'   => $leave->id,
            'metadata'     => ['status' => $request->status],
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        return response()->json($leave);
    }

    private function countBusinessDays(Carbon $from, Carbon $to): int
    {
        $holidays = DB::table('public_holidays')
            ->where('country', 'IN')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->toArray();

        $days    = 0;
        $current = $from->copy()->startOfDay();
        $end     = $to->copy()->startOfDay();

        while ($current->lte($end)) {
            if (! $current->isWeekend() && ! in_array($current->toDateString(), $holidays)) {
                $days++;
            }
            $current->addDay();
        }

        return $days;
    }
}
