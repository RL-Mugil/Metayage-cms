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
        'Annual'    => 'earned_leave',
        'Earned'    => 'earned_leave',
        'Sick'      => 'sick_leave',
        'Personal'  => 'casual_leave',
        'Casual'    => 'casual_leave',
        'Emergency' => 'casual_leave',
    ];

    /**
     * Transition a leave request out of Pending. On approval the employee's
     * balance is deducted; days beyond the available balance accrue as LOP.
     */
    public function resolve(LeaveRequest $leave, string $status, int $approverId, ?string $comments = null): LeaveRequest
    {
        return DB::transaction(function () use ($leave, $status, $approverId, $comments) {
            $locked = LeaveRequest::lockForUpdate()->findOrFail($leave->id);

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
