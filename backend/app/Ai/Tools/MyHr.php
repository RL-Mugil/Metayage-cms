<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Payslip;
use App\Models\User;
use App\Services\Payroll\PayslipSigner;

class MyHr extends Tool
{
    public function name(): string { return 'my_hr'; }

    public function title(): string { return 'My HR (leave, attendance, payslips)'; }

    public function description(): string
    {
        return 'The user\'s OWN HR details only: leave balance for this year, recent leave requests, this month\'s attendance summary, and their signed payslips with a 30-minute download link for each (set payslip_links false to skip links). Never anyone else\'s.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'payslip_links' => ['type' => 'boolean', 'description' => 'Include download links for signed payslips (default true).'],
            'payslips' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 24, 'description' => 'How many recent payslips (default 6).'],
        ]];
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function handle(User $user, array $args): array
    {
        $employee = Employee::with('designation')->where('user_id', $user->id)->first();
        if (! $employee) {
            return ['error' => 'Your login is not linked to an employee record. Ask HR to link it.'];
        }
        $balance = LeaveBalance::where('employee_id', $employee->id)->where('year', now()->year)->first();
        $attendance = Attendance::where('employee_id', $employee->id)->whereYear('attendance_date', now()->year)->whereMonth('attendance_date', now()->month)->get(['status', 'duration_minutes']);

        $signer = app(PayslipSigner::class);
        $links = ($args['payslip_links'] ?? true) !== false;
        $slips = Payslip::with(['components', 'run:id,period,status,run_type', 'internalSignature'])->where('employee_id', $employee->id)
            ->whereHas('run', fn ($r) => $r->whereIn('status', ['Finalized', 'Paid']))->orderByDesc('id')->limit(24)->get()
            ->filter(fn ($s) => $signer->isSigned($s))->sortByDesc(fn ($s) => $s->run?->period)->take(max(1, min(24, (int) ($args['payslips'] ?? 6))))->values();

        $files = [];
        $payslips = $slips->map(function ($s) use ($user, $links, &$files) {
            $row = ['month' => $s->run?->period?->format('M Y'), 'net_pay' => (float) $s->net_pay, 'gross' => (float) $s->gross_salary];
            if ($links) {
                $link = $this->fileLink($user, 'payslip', $s->id, 'pdf', [], 'payslip-'.$s->run?->period?->format('Y-m').'.pdf');
                $files[] = $link;
                $row['download_url'] = $link['url'];
            }

            return $row;
        })->all();

        return [
            'employee' => $employee->full_name, 'employee_code' => $employee->employee_code, 'designation' => $employee->designation?->title,
            'leave_balance_'.now()->year => $balance ? [
                'earned' => (float) $balance->earned_leave, 'casual' => (float) $balance->casual_leave, 'sick' => (float) $balance->sick_leave, 'lop_days' => (float) $balance->lop_days,
            ] : null,
            'recent_leave_requests' => LeaveRequest::where('employee_id', $employee->id)->orderByDesc('from_date')->limit(10)
                ->get(['leave_type', 'from_date', 'to_date', 'total_days', 'status'])
                ->map(fn ($l) => ['type' => $l->leave_type, 'from' => self::date($l->from_date), 'to' => self::date($l->to_date), 'days' => (float) $l->total_days, 'status' => $l->status])->all(),
            'attendance_'.now()->format('M_Y') => ['days_recorded' => $attendance->count()] + $attendance->groupBy('status')->map->count()->all()
                + ['hours' => round($attendance->sum('duration_minutes') / 60, 1)],
            'signed_payslips' => $payslips,
            'links_expire' => $files ? $files[0]['expires_at'] : null,
            'files' => $files,
        ];
    }
}
