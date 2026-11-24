<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\Payroll\PayslipBuilder;
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

    public function handle(): void
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
            $builder = app(PayslipBuilder::class);
            $ctx = $builder->context($period);

            // Delete any partial payslips from a previous failed attempt (cascades
            // to payslip_components via FK; un-links consumed claims via nullOnDelete).
            Payslip::where('payroll_run_id', $run->id)->delete();

            // Employees already paid this month through a manual payslip (Payroll
            // Portal → employee → Generate payslip) are not paid a second time.
            $manuallyPaid = Payslip::whereHas('run', fn ($q) => $q->where('run_type', 'Manual')->whereDate('period', $period->toDateString()))
                ->pluck('employee_id')->all();

            // Chunk to avoid loading the full employee table into memory at once.
            Employee::with('designation:id,title')
                ->where('employment_status', 'Active')
                ->where('exclude_from_payroll', false)
                ->whereNotIn('id', $manuallyPaid)
                ->chunk(100, function ($chunk) use ($run, $builder, $ctx, $period) {
                    DB::transaction(function () use ($chunk, $run, $builder, $ctx, $period) {
                        foreach ($chunk->filter(fn ($e) => (float) $e->base_pay_monthly > 0) as $emp) {
                            $builder->persist($run, $period, $builder->compute($emp, $period, $this->daysInMonth, $ctx));
                        }
                    });
                });

            $builder->refreshRunTotals($run);
            $run->update(['status' => 'Draft']);
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
