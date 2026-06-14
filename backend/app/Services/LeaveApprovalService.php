<?php

namespace App\Services;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveApprovalService
{
    // Frontend leave types → leave_balances column
    private const TYPE_COLUMN = [
        'Annual'           => 'earned_leave',
        'Earned'           => 'earned_leave',
        'Earned Leave'     => 'earned_leave',
        'Sick'             => 'sick_leave',
        'Sick Leave'       => 'sick_leave',
        'Personal'         => 'casual_leave',
        'Casual'           => 'casual_leave',
        'Casual Leave'     => 'casual_leave',
        'Emergency'        => 'casual_leave',
        'Emergency Leave'  => 'casual_leave',
    ];

    /**
     * Transition a leave request out of Pending. On approval the employee's
     * balance is deducted; days beyond the available balance accrue as LOP.
     */
    public function resolve(LeaveRequest $leave, string $status, int $approverId, ?string $comments = null): LeaveRequest
    {
        return DB::transaction(function () use ($leave, $status, $approverId, $comments) {
            $locked = LeaveRequest::lockForUpdate()->findOrFail($leave->id);

            // Cancellation of an already-approved leave: restore balance then mark Cancelled.
            if ($locked->status === 'Approved' && $status === 'Cancelled') {
                $column    = self::TYPE_COLUMN[$locked->leave_type] ?? 'casual_leave';
                $leaveYear = date('Y', strtotime($locked->from_date));
                $balance   = LeaveBalance::where('employee_id', $locked->employee_id)
                    ->where('year', $leaveYear)
                    ->lockForUpdate()
                    ->first();

                if ($balance) {
                    $days           = (float) $locked->total_days;
                    $currentLop     = (float) $balance->lop_days;
                    $lopToRestore     = min($currentLop, $days);
                    $balanceToRestore = $days - $lopToRestore;
                    $balance->update([
                        $column    => (float) $balance->{$column} + $balanceToRestore,
                        'lop_days' => max(0.0, $currentLop - $lopToRestore),
                    ]);
                }

                $locked->update(['status' => 'Cancelled', 'approved_by_id' => $approverId, 'comments' => $comments]);
                return $locked->fresh();
            }

            if ($locked->status !== 'Pending') {
                throw ValidationException::withMessages([
                    'status' => ["Request already {$locked->status}."],
                ]);
            }

            $locked->update([
                'status'         => $status,
                'approved_by_id' => $approverId,
                'comments'       => $comments,
            ]);

            if ($status === 'Approved') {
                $column  = self::TYPE_COLUMN[$locked->leave_type] ?? 'casual_leave';
                $balance = LeaveBalance::where('employee_id', $locked->employee_id)
                    ->where('year', date('Y'))
                    ->lockForUpdate()
                    ->first();

                if ($balance) {
                    $available = (float) $balance->{$column};
                    $days      = (float) $locked->total_days;
                    $deduct    = min($available, $days);
                    $shortfall = $days - $deduct;

                    $balance->update([
                        $column    => $available - $deduct,
                        'lop_days' => (float) $balance->lop_days + $shortfall,
                    ]);
                }
            }

            return $locked->fresh();
        });
    }
}
