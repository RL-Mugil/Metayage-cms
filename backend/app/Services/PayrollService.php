<?php

namespace App\Services;

/**
 * Indian salary computation, v1.
 *
 * Structure derived from monthly gross:
 *   Basic = 50% of gross, HRA = 50% of basic, Special Allowance = remainder.
 * Statutory deductions:
 *   PF (employee) = 12% of basic, capped at the EPF wage ceiling (basic 15,000 → max 1,800)
 *   ESI (employee) = 0.75% of gross, only when gross ≤ 21,000
 *   Professional Tax = flat 200/month (state-slab simplification)
 *   TDS = manual per payslip (regime/declaration handling is out of scope)
 * LOP prorates gross by calendar days before the structure is derived.
 */
class PayrollService
{
    private const PF_RATE = 0.12;
    private const PF_BASIC_CEILING = 15000.0;
    private const ESI_RATE = 0.0075;
    private const ESI_GROSS_LIMIT = 21000.0;

    // Professional Tax: flat simplification valid for Karnataka/Maharashtra slabs at
    // gross ≥ ₹15,001. Configurable via PAYROLL_PT_MONTHLY env var.
    // Full state-dependent slab support requires adding employees.work_state column.
    private float $professionalTax;

    public function __construct()
    {
        $this->professionalTax = (float) config('payroll.pt_monthly', 200.0);
    }

    /**
     * @return array<string, float> all payslip money fields
     */
    public function computeSlip(float $monthlyGross, float $lopDays, float $tds, int $daysInMonth): array
    {
        $lopDays = max(0.0, min($lopDays, (float) $daysInMonth));
        $lopDeduction = round($monthlyGross * $lopDays / $daysInMonth, 2);
        $payableGross = round($monthlyGross - $lopDeduction, 2);

        $basic = round($payableGross * 0.50, 2);
        $hra = round($basic * 0.50, 2);
        $special = round($payableGross - $basic - $hra, 2);

        $pf = round(min($basic, self::PF_BASIC_CEILING) * self::PF_RATE, 2);
        $esi = $payableGross <= self::ESI_GROSS_LIMIT ? round($payableGross * self::ESI_RATE, 2) : 0.0;
        $pt = $payableGross > 0 ? $this->professionalTax : 0.0;
        $tds = round(max(0.0, $tds), 2);

        $totalDeductions = round($pf + $esi + $pt + $tds, 2);
        $netPay = round($payableGross - $totalDeductions, 2);

        return [
            'lop_days' => $lopDays,
            'lop_deduction' => $lopDeduction,
            'basic' => $basic,
            'hra' => $hra,
            'special_allowance' => $special,
            'pf_employee' => $pf,
            'esi_employee' => $esi,
            'professional_tax' => $pt,
            'tds' => $tds,
            'total_deductions' => $totalDeductions,
            'net_pay' => max(0.0, $netPay),
        ];
    }
}
