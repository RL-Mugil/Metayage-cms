<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\PayrollService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GeneratePayrollJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(
        private readonly int $payrollRunId,
        private readonly int $daysInMonth
    ) {}

    public function handle(PayrollService $payroll): void
    {
        // Atomic Redis lock prevents two queue workers from processing the same
        // run concurrently. The status check alone is insufficient because the
        // status update happens at the END of the transaction.
        $lock = Cache::lock("payroll_run_processing_{$this->payrollRunId}", 360);
        if (! $lock->get()) {
            Log::warning("GeneratePayrollJob: run {$this->payrollRunId} already being processed by another worker, skipping.");
            return;
        }

        try {
            $run = PayrollRun::findOrFail($this->payrollRunId);

            if ($run->status !== 'Processing') {
                Log::warning("GeneratePayrollJob: run {$this->payrollRunId} is not in Processing state, skipping.");
                return;
            }

            $period = Carbon::parse($run->period);
            $periodMonth = (int) $period->format('m');
            $periodYear  = (int) $period->format('Y');

            // Pre-load LOP days per employee for this period from approved LOP leave requests.
            $lopByEmployee = LeaveRequest::where('status', 'Approved')
                ->whereIn('leave_type', ['LOP', 'Loss of Pay'])
                ->whereYear('from_date', $periodYear)
                ->whereMonth('from_date', $periodMonth)
                ->selectRaw('employee_id, SUM(total_days) as lop_total')
                ->groupBy('employee_id')
                ->pluck('lop_total', 'employee_id');

            // Delete any partial payslips from a previous failed attempt.
            Payslip::where('payroll_run_id', $run->id)->delete();

            // Chunk to avoid loading the full employee table into memory at once.
            Employee::with('designation:id,title')
                ->where('employment_status', 'Active')
                ->chunk(100, function ($chunk) use ($run, $payroll, $lopByEmployee) {
                    DB::transaction(function () use ($chunk, $run, $payroll, $lopByEmployee) {
                        foreach ($chunk->filter(fn ($e) => (float) $e->salary > 0) as $emp) {
                            $gross   = (float) $emp->salary;
                            $lopDays = (float) ($lopByEmployee[$emp->id] ?? 0);
                            $amounts = $payroll->computeSlip($gross, $lopDays, 0, $this->daysInMonth);

                            Payslip::create([
                                'payroll_run_id' => $run->id,
                                'employee_id'    => $emp->id,
                                'employee_name'  => $emp->full_name,
                                'employee_code'  => $emp->employee_code,
                                'designation'    => $emp->designation?->title,
                                'gross_salary'   => $gross,
                                ...$amounts,
                            ]);
                        }
                    });
                });

            $agg = Payslip::where('payroll_run_id', $run->id)
                ->selectRaw('count(*) as cnt, coalesce(sum(gross_salary - lop_deduction),0) as gross, coalesce(sum(total_deductions),0) as ded, coalesce(sum(net_pay),0) as net')
                ->first();

            $run->update([
                'status'            => 'Draft',
                'employee_count'    => (int) $agg->cnt,
                'gross_total'       => round((float) $agg->gross, 2),
                'deductions_total'  => round((float) $agg->ded, 2),
                'net_total'         => round((float) $agg->net, 2),
            ]);
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("GeneratePayrollJob failed for run {$this->payrollRunId}: " . $e->getMessage());
        PayrollRun::where('id', $this->payrollRunId)->update(['status' => 'Failed']);
    }
}
