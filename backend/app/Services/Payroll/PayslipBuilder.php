<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\LeaveRequest;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\LopAdjustment;
use App\Models\PayrollHour;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipComponent;
use App\Models\SalaryComponent;
use App\Models\SalaryRevision;
use App\Services\PayrollService;
use Illuminate\Support\Carbon;

/**
 * Builds one employee's payslip for a period — shared by the monthly run
 * (GeneratePayrollJob) and a manual payslip from the Payroll Portal employee page,
 * so both always compute the same way: approved salary revision, approved LOP leave,
 * loan installments, approved reimbursements, pending LOP adjustments, the April
 * annual-hours settlement, then the template components and statutory deductions.
 */
class PayslipBuilder
{
    public function __construct(private readonly PayrollService $payroll) {}

    /**
     * Everything a period needs, loaded once (for the whole run, or one employee).
     *
     * @return array<string, mixed>
     */
    public function context(Carbon $period, ?int $employeeId = null): array
    {
        $only = fn ($q) => $employeeId ? $q->where('employee_id', $employeeId) : $q;

        return [
            'components' => SalaryComponent::activeOrdered(),
            // LOP days per employee for this period from approved LOP leave requests.
            'lop' => $only(LeaveRequest::where('status', 'Approved')
                ->whereIn('leave_type', ['LOP', 'Loss of Pay'])
                ->whereYear('from_date', $period->year)
                ->whereMonth('from_date', $period->month))
                ->selectRaw('employee_id, SUM(total_days) as lop_total')
                ->groupBy('employee_id')
                ->pluck('lop_total', 'employee_id'),
            // Approved claims not yet paid in any payslip.
            'claims' => $only(ExpenseClaim::where('status', 'Approved')->whereNull('payslip_id'))->get()->groupBy('employee_id'),
            // Excludes a loan already deducted for this exact period (a retried run).
            'loans' => $only(Loan::where('status', Loan::STATUS_ACTIVE)
                ->where('remaining_balance', '>', 0)
                ->where('start_period', '<=', $period->toDateString())
                ->whereDoesntHave('repayments', fn ($q) => $q->whereDate('period', $period->toDateString())))
                ->get()->groupBy('employee_id'),
            // Pending prior-month LOP corrections/reversals.
            'lop_adjustments' => $only(LopAdjustment::where('status', LopAdjustment::STATUS_PENDING))->get()->groupBy('employee_id'),
        ];
    }

    /**
     * Computes the payslip without saving anything.
     *
     * @param  array<string, mixed>  $ctx  from context()
     * @param  array{lop_days?: float|null, tds?: float|null, health_insurance?: float|null, other_deductions?: float|null}  $overrides
     * @return array{fields: array<string, mixed>, components: array<int, array<string, mixed>>, claims: \Illuminate\Support\Collection, loans: \Illuminate\Support\Collection, lop_adjustments: \Illuminate\Support\Collection}
     */
    public function compute(Employee $emp, Carbon $period, int $daysInMonth, array $ctx, array $overrides = []): array
    {
        // Approved revisions apply only from their effective month.
        $revision = SalaryRevision::where('employee_id', $emp->id)
            ->where('status', SalaryRevision::STATUS_APPROVED)
            ->whereDate('effective_period', '<=', $period->toDateString())
            ->orderByDesc('effective_period')->first();
        $payrollEmployee = clone $emp;
        if ($revision) {
            $raw = $payrollEmployee->getAttributes();
            $raw['base_pay_monthly'] = (string) $revision->new_base_pay;
            if ($revision->new_hra !== null) {
                $raw['hra_monthly'] = (string) $revision->new_hra;
            }
            $payrollEmployee->setRawAttributes($raw);
        }
        $lopDays = isset($overrides['lop_days']) ? (float) $overrides['lop_days'] : (float) ($ctx['lop'][$emp->id] ?? 0);

        // Each active loan's installment, capped at its remaining balance, folded into other deductions.
        $loanInstallments = $ctx['loans']->get($emp->id, collect())->map(fn ($loan) => [
            'loan' => $loan,
            'amount' => round(min((float) $loan->monthly_installment, (float) $loan->remaining_balance), 2),
        ])->filter(fn ($row) => $row['amount'] > 0);
        $otherDeductions = round($loanInstallments->sum('amount') + (float) ($overrides['other_deductions'] ?? 0), 2);

        $amounts = $this->payroll->computeSlip($payrollEmployee, $lopDays, (float) ($overrides['tds'] ?? 0), $daysInMonth, $period->month,
            $ctx['components'], (bool) $emp->professional_tax_exempt, (float) ($overrides['health_insurance'] ?? 0), $otherDeductions);
        $hours = PayrollHour::where('employee_id', $emp->id)->whereDate('period', $period->toDateString())->value('minutes');

        // The April run settles the completed April–March hours year; a missing month leaves it unresolved.
        $annualShortage = 0;
        $annualAdjustment = 0.0;
        if ($period->month === 4) {
            $priorYear = $period->copy()->subYear();
            $priorPeriods = collect(range(0, 11))->map(fn ($i) => $priorYear->copy()->addMonths($i)->toDateString());
            $priorHours = PayrollHour::where('employee_id', $emp->id)->whereIn('period', $priorPeriods)->pluck('minutes');
            if ($priorHours->count() === 12) {
                $annualShortage = max(0, 181 * 60 * 12 - (int) $priorHours->sum());
                if ($annualShortage > 0) {
                    $basis = collect($amounts['components'])->whereIn('code', ['BASIC', 'VARIABLE_PAY', 'HRA'])->sum('amount');
                    $annualAdjustment = round(($basis / (181 * 60)) * $annualShortage, 2);
                    $amounts['total_deductions'] = round($amounts['total_deductions'] + $annualAdjustment, 2);
                    $amounts['net_pay'] = max(0.0, round($amounts['net_pay'] - $annualAdjustment, 2));
                }
            }
        }
        $gross = (float) $amounts['monthly_gross'];

        $claims = $ctx['claims']->get($emp->id, collect());
        $lopAdjustments = $ctx['lop_adjustments']->get($emp->id, collect());
        $perDayRate = $daysInMonth > 0 ? $gross / $daysInMonth : 0.0;
        $lopAdjustmentTotal = round($lopAdjustments->sum(function ($adj) use ($perDayRate) {
            $amount = round($perDayRate * (float) $adj->days, 2);

            return $adj->adjustment_type === LopAdjustment::TYPE_REVERSAL ? $amount : -$amount;
        }), 2);

        $componentRows = $amounts['components'];
        unset($amounts['components'], $amounts['ctc_addon_total'], $amounts['total_ctc'], $amounts['monthly_gross'], $amounts['payable_gross']);

        return [
            'fields' => [
                'employee_id' => $emp->id,
                'employee_name' => $emp->full_name,
                'employee_code' => $emp->employee_code,
                'designation' => $emp->designation?->title,
                'gross_salary' => $gross,
                ...$amounts,
                // Net salary excludes reimbursements; they are shown separately and paid on top.
                'net_pay' => max(0.0, round((float) $amounts['net_pay'] + $lopAdjustmentTotal, 2)),
                'reimbursements' => round((float) $claims->sum('amount'), 2),
                'lop_adjustment_amount' => $lopAdjustmentTotal,
                'hours_logged_minutes' => $hours,
                'annual_hours_shortage_minutes' => $annualShortage,
                'annual_hours_adjustment' => $annualAdjustment,
            ],
            'components' => $componentRows,
            'claims' => $claims,
            'loans' => $loanInstallments,
            'lop_adjustments' => $lopAdjustments,
        ];
    }

    /** Saves a computed payslip into the run and consumes its claims, loan installments and LOP adjustments. */
    public function persist(PayrollRun $run, Carbon $period, array $computed): Payslip
    {
        $payslip = Payslip::create(['payroll_run_id' => $run->id, ...$computed['fields']]);

        if ($computed['lop_adjustments']->isNotEmpty()) {
            LopAdjustment::whereIn('id', $computed['lop_adjustments']->pluck('id'))->update([
                'status' => LopAdjustment::STATUS_APPLIED, 'applied_in_run_id' => $run->id, 'payslip_id' => $payslip->id,
            ]);
        }
        if (! empty($computed['components'])) {
            $now = now();
            PayslipComponent::insert(array_map(fn ($c) => [
                'payslip_id' => $payslip->id, 'code' => $c['code'], 'name' => $c['name'], 'calc_type' => $c['calc_type'],
                'category' => $c['category'], 'value' => $c['value'], 'percentage_of' => $c['percentage_of'],
                'sequence' => $c['sequence'], 'amount' => $c['amount'], 'created_at' => $now, 'updated_at' => $now,
            ], $computed['components']));
        }
        if ($computed['claims']->isNotEmpty()) {
            ExpenseClaim::whereIn('id', $computed['claims']->pluck('id'))->update(['payslip_id' => $payslip->id]);
        }
        foreach ($computed['loans'] as $row) {
            /** @var Loan $loan */
            $loan = $row['loan'];
            LoanRepayment::create([
                'loan_id' => $loan->id, 'payslip_id' => $payslip->id, 'installment_amount' => $row['amount'],
                'period' => $period->toDateString(), 'status' => LoanRepayment::STATUS_DEDUCTED,
            ]);
            $newBalance = round((float) $loan->remaining_balance - $row['amount'], 2);
            $loan->update(['remaining_balance' => max(0.0, $newBalance), 'status' => $newBalance <= 0 ? Loan::STATUS_CLOSED : Loan::STATUS_ACTIVE]);
        }

        return $payslip;
    }

    /** Recomputes a run's headline totals from its payslips. */
    public function refreshRunTotals(PayrollRun $run): void
    {
        $agg = Payslip::where('payroll_run_id', $run->id)
            ->selectRaw('count(*) as cnt, coalesce(sum(gross_salary - lop_deduction),0) as gross, coalesce(sum(total_deductions),0) as ded, coalesce(sum(net_pay + reimbursements),0) as net')
            ->first();
        $run->update([
            'employee_count' => (int) $agg->cnt,
            'gross_total' => round((float) $agg->gross, 2),
            'deductions_total' => round((float) $agg->ded, 2),
            'net_total' => round((float) $agg->net, 2),
        ]);
    }
}
