<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\PayrollService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
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
        $run = PayrollRun::findOrFail($this->payrollRunId);

        if ($run->status !== 'Processing') {
            Log::warning("GeneratePayrollJob: run {$this->payrollRunId} is not in Processing state, skipping.");
            return;
        }

        $period = Carbon::parse($run->period);

        // Salary is encrypted — CAST in SQL fails; filter in PHP after decryption.
        $eligible = Employee::with('designation:id,title')
            ->where('employment_status', 'Active')
            ->get()
            ->filter(fn ($e) => (float) $e->salary > 0);

        DB::transaction(function () use ($run, $eligible, $payroll) {
            // Delete any partial payslips if this is a retry
            Payslip::where('payroll_run_id', $run->id)->delete();

            foreach ($eligible as $emp) {
                $gross = (float) $emp->salary;
                $amounts = $payroll->computeSlip($gross, 0, 0, $this->daysInMonth);

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
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::error("GeneratePayrollJob failed for run {$this->payrollRunId}: " . $e->getMessage());
        PayrollRun::where('id', $this->payrollRunId)->update(['status' => 'Failed']);
    }
}
