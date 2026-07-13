<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;

class LeaveBalanceService
{
    public const DEFAULT_ENTITLEMENTS = [
        'earned_leave' => 15.0,
        'casual_leave' => 8.0,
        'sick_leave' => 7.0,
        'maternity_leave' => 0.0,
        'lop_days' => 0.0,
    ];

    private const TYPE_COLUMN = [
        'Annual' => 'earned_leave',
        'Earned' => 'earned_leave',
        'Earned Leave' => 'earned_leave',
        'Sick' => 'sick_leave',
        'Sick Leave' => 'sick_leave',
        'Personal' => 'casual_leave',
        'Casual' => 'casual_leave',
        'Casual Leave' => 'casual_leave',
        'Emergency' => 'casual_leave',
        'Emergency Leave' => 'casual_leave',
    ];

    public function currentYearBalance(Employee $employee, ?int $year = null): LeaveBalance
    {
        $year ??= (int) now()->year;

        $existing = LeaveBalance::where('employee_id', $employee->id)
            ->where('year', $year)
            ->first();

        if ($existing) {
            return $existing;
        }

        $computed = $this->computeBalanceFromApprovedLeaves($employee, $year);

        return LeaveBalance::firstOrCreate(
            ['employee_id' => $employee->id, 'year' => $year],
            $computed
        );
    }

    public function entitlements(): array
    {
        return self::DEFAULT_ENTITLEMENTS;
    }

    private function computeBalanceFromApprovedLeaves(Employee $employee, int $year): array
    {
        $remaining = self::DEFAULT_ENTITLEMENTS;

        LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'Approved')
            ->whereYear('from_date', $year)
            ->orderBy('from_date')
            ->get()
            ->each(function (LeaveRequest $leave) use (&$remaining) {
                $days = (float) $leave->total_days;

                if (in_array($leave->leave_type, ['LOP', 'Loss of Pay'], true)) {
                    $remaining['lop_days'] += $days;
                    return;
                }

                $column = self::TYPE_COLUMN[$leave->leave_type] ?? 'casual_leave';
                $deduct = min($remaining[$column], $days);
                $remaining[$column] -= $deduct;
                $remaining['lop_days'] += $days - $deduct;
            });

        return $remaining;
    }
}
