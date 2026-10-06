<?php

namespace App\Services;

use App\Models\PayrollRun;
use App\Models\TdsLiability;
use Illuminate\Support\Carbon;

/**
 * Generates one TdsLiability record per employee per period once a pay run is
 * approved (Zoho's documented trigger — see /taxes-and-forms/tds-liabilities.html).
 * Called from PayrollController::approve(), the single place a run's status
 * becomes Finalized.
 */
class TdsLiabilityService
{
    public function generateForRun(PayrollRun $run): void
    {
        $run->loadMissing('payslips');
        $period = Carbon::parse($run->period);

        // Indian FY: Apr-Mar. FY "2026" means Apr 2026 - Mar 2027.
        $financialYear = $period->month >= 4 ? $period->year : $period->year - 1;
        $quarter = (int) ceil((($period->month - 4 + 12) % 12 + 1) / 3); // Apr-Jun=1 ... Jan-Mar=4
        $dueDate = $period->month === 3
            ? Carbon::create($period->year, 4, 30)
            : $period->copy()->addMonth()->startOfMonth()->addDays(6);

        foreach ($run->payslips as $payslip) {
            if ((float) $payslip->tds <= 0) {
                continue;
            }

            TdsLiability::updateOrCreate(
                ['payroll_run_id' => $run->id, 'employee_id' => $payslip->employee_id],
                [
                    'payslip_id' => $payslip->id,
                    'financial_year' => $financialYear,
                    'quarter' => $quarter,
                    'period' => $period->toDateString(),
                    'taxable_amount' => round((float) $payslip->gross_salary - (float) $payslip->lop_deduction, 2),
                    'tds_deducted' => $payslip->tds,
                    'due_date' => $dueDate->toDateString(),
                ]
            );
        }
    }
}
