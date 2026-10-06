<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\LeaveRequest;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\LopAdjustment;
use App\Models\PayrollRun;
use App\Models\PayrollHour;
use App\Models\SalaryRevision;
use App\Models\Payslip;
use App\Models\PayslipComponent;
use App\Models\SalaryComponent;
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

            // Loaded once for the whole run, not per employee — both are cheap,
            // rarely-changing lookups. Claims consumed by a now-deleted payslip
            // (see the delete below) are already un-linked by the DB's
            // nullOnDelete FK on expense_claims.payslip_id, so this always sees
            // every genuinely-unpaid approved claim, including a retried run's own.
            $components = SalaryComponent::activeOrdered();
            $reimbursementsByEmployee = ExpenseClaim::where('status', 'Approved')
                ->whereNull('payslip_id')
                ->get()
                ->groupBy('employee_id');
            // Excludes a loan already deducted for this exact period — guards a
            // retried job (after a failed earlier attempt already decremented the
            // balance) from deducting the same installment twice.
            $loansByEmployee = Loan::where('status', Loan::STATUS_ACTIVE)
                ->where('remaining_balance', '>', 0)
                ->where('start_period', '<=', $period->toDateString())
                ->whereDoesntHave('repayments', fn ($q) => $q->whereDate('period', $period->toDateString()))
                ->get()
                ->groupBy('employee_id');
            // Deliberately NOT auto-folding outstanding withheld salary here —
            // unlike LOP adjustments below, Zoho's "Release Withheld Salary" is
            // an explicit, selective HR action (PayrollController::releaseWithheld)
            // taken against an already-generated draft run, not something every
            // new run absorbs automatically.
            // Pending prior-month LOP corrections/reversals (see
            // PayrollController::createLopAdjustment), folded additively into
            // this run's net_pay — Reversal credits back, Correction deducts more.
            $lopAdjustmentsByEmployee = LopAdjustment::where('status', LopAdjustment::STATUS_PENDING)
                ->get()
                ->groupBy('employee_id');

            // Delete any partial payslips from a previous failed attempt (cascades
            // to payslip_components via FK; un-links consumed claims via nullOnDelete).
            Payslip::where('payroll_run_id', $run->id)->delete();

            $calendarMonth = $periodMonth;

            // Chunk to avoid loading the full employee table into memory at once.
            Employee::with('designation:id,title')
                ->where('employment_status', 'Active')
                ->where('exclude_from_payroll', false)
                ->chunk(100, function ($chunk) use ($run, $payroll, $lopByEmployee, $components, $calendarMonth, $reimbursementsByEmployee, $loansByEmployee, $lopAdjustmentsByEmployee, $period) {
                    DB::transaction(function () use ($chunk, $run, $payroll, $lopByEmployee, $components, $calendarMonth, $reimbursementsByEmployee, $loansByEmployee, $lopAdjustmentsByEmployee, $period) {
                        foreach ($chunk->filter(fn ($e) => (float) $e->base_pay_monthly > 0) as $emp) {
                            // Approved revisions become effective only for runs on
                            // or after their effective month; historical runs keep
                            // the salary that was in force at that time.
                            $revision = SalaryRevision::where('employee_id', $emp->id)
                                ->where('status', SalaryRevision::STATUS_APPROVED)
                                ->whereDate('effective_period', '<=', $period->toDateString())
                                ->orderByDesc('effective_period')->first();
                            $payrollEmployee = clone $emp;
                            if ($revision) {
                                $raw = $payrollEmployee->getAttributes();
                                $raw['base_pay_monthly'] = (string) $revision->new_base_pay;
                                if ($revision->new_hra !== null) $raw['hra_monthly'] = (string) $revision->new_hra;
                                $payrollEmployee->setRawAttributes($raw);
                            }
                            $lopDays = (float) ($lopByEmployee[$emp->id] ?? 0);

                            // Each active loan's installment, capped at its own remaining
                            // balance (the final payment is often smaller than the regular
                            // installment), folded into `other_deductions` as one lump sum.
                            $loans = $loansByEmployee->get($emp->id, collect());
                            $loanInstallments = $loans->map(fn ($loan) => [
                                'loan' => $loan,
                                'amount' => round(min((float) $loan->monthly_installment, (float) $loan->remaining_balance), 2),
                            ])->filter(fn ($row) => $row['amount'] > 0);
                            $loanDeductionTotal = round($loanInstallments->sum('amount'), 2);

                            $amounts = $payroll->computeSlip($payrollEmployee, $lopDays, 0, $this->daysInMonth, $calendarMonth, $components, (bool) $emp->professional_tax_exempt, 0.0, $loanDeductionTotal);
                            $hours = PayrollHour::where('employee_id', $emp->id)->whereDate('period', $period->toDateString())->value('minutes');
                            $annualShortage = 0;
                            $annualAdjustment = 0.0;
                            // The April run settles the completed April–March year.
                            // A missing month is left unresolved rather than treated as zero.
                            if ($calendarMonth === 4) {
                                $priorYear = $period->copy()->subYear();
                                $priorPeriods = collect(range(0, 11))->map(fn ($i) => $priorYear->copy()->addMonths($i)->toDateString());
                                $priorHours = PayrollHour::where('employee_id', $emp->id)->whereIn('period', $priorPeriods)->pluck('minutes');
                                if ($priorHours->count() === 12) {
                                    $target = 181 * 60 * 12;
                                    $annualShortage = max(0, $target - (int) $priorHours->sum());
                                    if ($annualShortage > 0) {
                                        $basis = collect($amounts['components'])->whereIn('code', ['BASIC', 'VARIABLE_PAY', 'HRA'])->sum('amount');
                                        $annualAdjustment = round(($basis / (181 * 60)) * $annualShortage, 2);
                                        $amounts['total_deductions'] = round($amounts['total_deductions'] + $annualAdjustment, 2);
                                        $amounts['net_pay'] = max(0.0, round($amounts['net_pay'] - $annualAdjustment, 2));
                                    }
                                }
                            }
                            $gross = (float) $amounts['monthly_gross'];

                            $claims = $reimbursementsByEmployee->get($emp->id, collect());
                            $reimbursementTotal = round((float) $claims->sum('amount'), 2);

                            $lopAdjustments = $lopAdjustmentsByEmployee->get($emp->id, collect());
                            $perDayRate = $this->daysInMonth > 0 ? $gross / $this->daysInMonth : 0.0;
                            $lopAdjustmentTotal = round($lopAdjustments->sum(function ($adj) use ($perDayRate) {
                                $amount = round($perDayRate * (float) $adj->days, 2);
                                return $adj->adjustment_type === LopAdjustment::TYPE_REVERSAL ? $amount : -$amount;
                            }), 2);

                            $componentRows = $amounts['components'];
                            // ctc_addon_total/total_ctc are display-only — derivable at
                            // render time from payslip_components, not Payslip columns.
                            // monthly_gross/payable_gross were only needed locally above.
                            unset($amounts['components'], $amounts['ctc_addon_total'], $amounts['total_ctc'], $amounts['monthly_gross'], $amounts['payable_gross']);

                            $payslip = Payslip::create([
                                'payroll_run_id' => $run->id,
                                'employee_id'    => $emp->id,
                                'employee_name'  => $emp->full_name,
                                'employee_code'  => $emp->employee_code,
                                'designation'    => $emp->designation?->title,
                                'gross_salary'   => $gross,
                                ...$amounts,
                                // Net salary excludes reimbursements; reimbursement is shown
                                // separately for final verification and payout reconciliation.
                                'net_pay'        => max(0.0, round((float) $amounts['net_pay'] + $lopAdjustmentTotal, 2)),
                                'reimbursements' => $reimbursementTotal,
                                'lop_adjustment_amount' => $lopAdjustmentTotal,
                                'hours_logged_minutes' => $hours,
                                'annual_hours_shortage_minutes' => $annualShortage,
                                'annual_hours_adjustment' => $annualAdjustment,
                            ]);

                            if ($lopAdjustments->isNotEmpty()) {
                                LopAdjustment::whereIn('id', $lopAdjustments->pluck('id'))->update([
                                    'status' => LopAdjustment::STATUS_APPLIED,
                                    'applied_in_run_id' => $run->id,
                                    'payslip_id' => $payslip->id,
                                ]);
                            }

                            if (! empty($componentRows)) {
                                $now = now();
                                PayslipComponent::insert(array_map(
                                    fn ($c) => [
                                        'payslip_id' => $payslip->id,
                                        'code' => $c['code'],
                                        'name' => $c['name'],
                                        'calc_type' => $c['calc_type'],
                                        'category' => $c['category'],
                                        'value' => $c['value'],
                                        'percentage_of' => $c['percentage_of'],
                                        'sequence' => $c['sequence'],
                                        'amount' => $c['amount'],
                                        'created_at' => $now,
                                        'updated_at' => $now,
                                    ],
                                    $componentRows
                                ));
                            }

                            if ($claims->isNotEmpty()) {
                                ExpenseClaim::whereIn('id', $claims->pluck('id'))->update(['payslip_id' => $payslip->id]);
                            }

                            foreach ($loanInstallments as $row) {
                                /** @var Loan $loan */
                                $loan = $row['loan'];
                                LoanRepayment::create([
                                    'loan_id' => $loan->id,
                                    'payslip_id' => $payslip->id,
                                    'installment_amount' => $row['amount'],
                                    'period' => $period->toDateString(),
                                    'status' => LoanRepayment::STATUS_DEDUCTED,
                                ]);
                                $newBalance = round((float) $loan->remaining_balance - $row['amount'], 2);
                                $loan->update([
                                    'remaining_balance' => max(0.0, $newBalance),
                                    'status' => $newBalance <= 0 ? Loan::STATUS_CLOSED : Loan::STATUS_ACTIVE,
                                ]);
                            }
                        }
                    });
                });

            $agg = Payslip::where('payroll_run_id', $run->id)
                ->selectRaw('count(*) as cnt, coalesce(sum(gross_salary - lop_deduction),0) as gross, coalesce(sum(total_deductions),0) as ded, coalesce(sum(net_pay + reimbursements),0) as net')
                ->first();

            $run->update([
                'status'            => 'Draft',
                'employee_count'    => (int) $agg->cnt,
                'gross_total'       => round((float) $agg->gross, 2),
                'deductions_total'  => round((float) $agg->ded, 2),
                // net_total already includes each payslip's reimbursements, since
                // net_pay is stored inclusive of them — see the Payslip::create above.
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
