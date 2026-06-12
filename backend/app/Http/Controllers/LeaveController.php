<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $employee = Employee::where('user_id', $user->id)->first();

        $requests = LeaveRequest::with('employee:id,full_name', 'approvedBy:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($r) => [
                'id'            => $r->id,
                'leave_type'    => $r->leave_type,
                'from_date'     => $r->from_date,
                'to_date'       => $r->to_date,
                'total_days'    => (float) $r->total_days,
                'reason'        => $r->reason,
                'status'        => $r->status,
                'approved_by'   => $r->approvedBy?->name,
                'comments'      => $r->comments,
                'is_mine'       => $r->employee_id === $employee?->id,
                'employee_name' => $r->employee?->full_name ?? '—',
            ]);

        $balance = null;
        if ($employee) {
            $bal = LeaveBalance::where('employee_id', $employee->id)
                ->where('year', date('Y'))
                ->first();
            if ($bal) {
                $balance = [
                    'earned_leave'    => (float) $bal->earned_leave,
                    'casual_leave'    => (float) $bal->casual_leave,
                    'sick_leave'      => (float) $bal->sick_leave,
                    'maternity_leave' => (float) $bal->maternity_leave,
                    'lop_days'        => (float) $bal->lop_days,
                ];
            }
        }

        return response()->json([
            'requests' => $requests,
            'balances' => $balance,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $employee = Employee::where('user_id', $user->id)->first();

        if (! $employee) {
            return response()->json(['message' => 'Employee profile not found'], 422);
        }

        $validated = $request->validate([
            'leave_type'  => 'required|in:Annual,Earned,Sick,Personal,Casual,Emergency',
            'from_date'   => 'required|date_format:Y-m-d',
            'to_date'     => 'required|date_format:Y-m-d|after_or_equal:from_date',
            'reason'      => 'nullable|string|max:1000',
        ]);

        $from = new \DateTime($validated['from_date']);
        $to = new \DateTime($validated['to_date']);
        $to->modify('+1 day'); // to_date is inclusive, interval excludes end
        $interval = $from->diff($to);
        $totalDays = $interval->days;

        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type'  => $validated['leave_type'],
            'from_date'   => $validated['from_date'],
            'to_date'     => $validated['to_date'],
            'total_days'  => $totalDays,
            'reason'      => $validated['reason'] ?? null,
            'status'      => 'Pending',
        ]);

        return response()->json([
            'id'            => $leave->id,
            'leave_type'    => $leave->leave_type,
            'from_date'     => $leave->from_date,
            'to_date'       => $leave->to_date,
            'total_days'    => (float) $leave->total_days,
            'status'        => $leave->status,
            'is_mine'       => true,
            'employee_name' => $employee->full_name,
        ], 201);
    }
}
