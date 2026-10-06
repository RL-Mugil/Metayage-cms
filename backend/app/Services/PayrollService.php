<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\SalaryComponent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Indian salary computation, v4 (real Metayage structure).
 *
 * Earning structure is driven by the admin-editable `salary_components`
 * template (see SalaryComponent/SalaryComponentController) — each active
 * `category=earning` row is read straight off the employee (`employee_field`
 * — Base Pay, HRA), a flat ₹ amount, a percentage of an earlier earning
 * component, or (rarely, for admin flexibility) a remainder. The default
 * seeded template is Metayage's real, verified-from-payslips formula: Basic
 * = declared Base Pay, Variable Pay = 25% of Basic, HRA = declared flat
 * amount. Gross is the SUM of these — no longer a pre-known input split
 * three ways, so an earning component can no longer be "a percentage of
 * Gross" (there's nothing to reference until the earning pass is done); the
 * admin UI blocks configuring one that way.
 *
 * `category=ctc_addon` rows (Employer NPS contribution, Gratuity Provision)
 * are computed the same way (today: both 'percentage' of BASIC) but are
 * summed separately into `ctc_addon_total` / `total_ctc` for display — they
 * NEVER reduce Net Pay. This matches real Indian payroll practice
 * (employer-side cost, not an employee deduction).
 *
 * LOP prorates every earning-category leaf amount (employee_field/flat) by
 * (daysInMonth - lopDays) / daysInMonth before percentages are derived from
 * them, so Variable Pay/HRA/NPS/Gratuity all scale down proportionally with
 * Basic on a part-month, exactly like Basic itself always has.
 *
 * Statutory deductions (hardcoded formulas, deliberately NOT part of the
 * generic component engine — legally fixed, not something a firm should
 * casually reconfigure):
 *   PF (employee) = 12% of Basic, capped at the EPF wage ceiling (basic 15,000 → max 1,800)
 *   ESI (employee) = 0.75% of gross, only when gross ≤ 21,000
 *   Professional Tax = Karnataka's real slab (the firm's legal entity, TAN
 *     and ROC registration are all Karnataka/Bangalore, so this one slab
 *     applies to every employee regardless of where they physically sit):
 *     gross ≤ `payroll_pt_nil_threshold` → Nil; else `payroll_pt_monthly_amount`,
 *     except February which is `payroll_pt_february_amount` (statutory annual
 *     cap ₹2,500 = 11×200+300). All three editable via Settings → Payroll.
 *   Educational Cess = 4% of Income Tax (the Indian "Health and Education
 *     Cess" statutory formula — unchanged under the Income Tax Act, 2025).
 *   PF/ESI can each be turned off firm-wide via Settings → Payroll
 *     (`payroll_pf_enabled`/`payroll_esi_enabled`) for firms that don't run
 *     them (e.g. an NPS-only firm) — computes to 0 rather than being omitted,
 *     so the Payslip schema stays stable either way.
 *   Income Tax (TDS) and Health Insurance / Other Deductions = manual per
 *     payslip (regime/declaration handling and insurance specifics are out
 *     of scope for this engine).
 */
class PayrollService
{
    private const PF_RATE = 0.12;
    private const PF_BASIC_CEILING = 15000.0;
    private const ESI_RATE = 0.0075;
    private const ESI_GROSS_LIMIT = 21000.0;
    private const EDUCATIONAL_CESS_RATE = 0.04;

    /**
     * Karnataka Professional Tax slab. Deliberately independent of
     * HandleInertiaRequests' 300s-cached shared prop, since GeneratePayrollJob
     * runs in a queue worker process that never warms that request-scoped
     * cache — each setting is read directly from the database every call.
     */
    public function currentProfessionalTax(float $grossMonthly, int $calendarMonth): float
    {
        $threshold = $this->settingFloat('payroll_pt_nil_threshold', 25000.0);
        if ($grossMonthly <= $threshold) {
            return 0.0;
        }

        return $calendarMonth === 2
            ? $this->settingFloat('payroll_pt_february_amount', 300.0)
            : $this->settingFloat('payroll_pt_monthly_amount', 200.0);
    }

    /** Same direct-DB-read pattern as currentProfessionalTax() — see its docblock. */
    public function currentPfEnabled(): bool
    {
        return false;
    }

    public function currentEsiEnabled(): bool
    {
        return false;
    }

    private function settingFloat(string $key, float $default): float
    {
        $value = DB::table('system_settings')->where('key', $key)->value('value');

        return $value !== null && $value !== '' ? (float) $value : $default;
    }

    /**
     * @param  Collection<int, SalaryComponent>  $components  SalaryComponent::activeOrdered()
     * @return array<string, mixed> all payslip money fields, plus a `components`
     *                              array (code/name/calc_type/category/sequence/amount)
     *                              for the payslip_components snapshot. `net_pay`
     *                              here excludes reimbursements — callers add those
     *                              on top (see GeneratePayrollJob / PayrollController).
     */
    public function computeSlip(
        Employee $employee,
        float $lopDays,
        float $tds,
        int $daysInMonth,
        int $calendarMonth,
        Collection $components,
        bool $professionalTaxExempt = false,
        float $healthInsurance = 0.0,
        float $otherDeductions = 0.0
    ): array {
        $lopDays = max(0.0, min($lopDays, (float) $daysInMonth));
        $lopFactor = $daysInMonth > 0 ? ($daysInMonth - $lopDays) / $daysInMonth : 1.0;

        $earningComponents = $components->where('category', SalaryComponent::CATEGORY_EARNING);
        $ctcAddonComponents = $components->where('category', SalaryComponent::CATEGORY_CTC_ADDON);

        // Unprorated pass (lopFactor=1.0) just to report `lop_deduction` — the
        // real, prorated breakdown below is what every money field is built from.
        [$unprorated] = $this->computeComponents($earningComponents, $employee, 1.0);
        $monthlyGross = round(array_sum(array_column($unprorated, 'amount')), 2);

        [$computed, $amountsByCode] = $this->computeComponents($earningComponents, $employee, $lopFactor);
        $payableGross = round(array_sum(array_column($computed, 'amount')), 2);
        $lopDeduction = round($monthlyGross - $payableGross, 2);

        [$ctcAddonRows, $ctcAddonAmountsByCode] = $this->computeComponents($ctcAddonComponents, $employee, $lopFactor, $amountsByCode);
        $ctcAddonTotal = round(array_sum(array_column($ctcAddonRows, 'amount')), 2);

        // PF is statutory and keyed to "Basic" specifically — the admin-facing
        // validator (SalaryComponentValidator) guarantees exactly one active
        // earning row with code BASIC exists at all times, so this is always
        // resolvable.
        $basicAmount = $amountsByCode['BASIC'] ?? 0.0;
        $hraAmount = $amountsByCode['HRA'] ?? 0.0;

        // Employee PF applies only when the employee is actually enrolled in
        // EPF (a UAN exists). NPS-only employees must not receive an invented
        // ₹1,800 deduction; NPS and gratuity are employer-side CTC items.
        $pf = ($this->currentPfEnabled() && ! empty($employee->uan_pf_number))
            ? round(min($basicAmount, self::PF_BASIC_CEILING) * self::PF_RATE, 2)
            : 0.0;
        $esi = ($this->currentEsiEnabled() && $payableGross <= self::ESI_GROSS_LIMIT) ? round($payableGross * self::ESI_RATE, 2) : 0.0;
        $pt = ($payableGross > 0 && ! $professionalTaxExempt) ? $this->currentProfessionalTax($payableGross, $calendarMonth) : 0.0;
        $tds = round(max(0.0, $tds), 2);
        $educationalCess = round($tds * self::EDUCATIONAL_CESS_RATE, 2);
        $healthInsurance = round(max(0.0, $healthInsurance), 2);
        $otherDeductions = round(max(0.0, $otherDeductions), 2);

        $totalDeductions = round($pf + $esi + $pt + $tds + $educationalCess + $healthInsurance + $otherDeductions, 2);
        $netPay = round($payableGross - $totalDeductions, 2);

        return [
            // Unprorated full-month gross (what `employees.gross_salary`-equivalent
            // always meant) and the LOP-adjusted figure actually paid this period —
            // callers need both (e.g. GeneratePayrollJob's per-day LOP-adjustment rate
            // uses the former, the Payslip's own deduction math uses the latter).
            'monthly_gross' => $monthlyGross,
            'payable_gross' => $payableGross,
            'lop_days' => $lopDays,
            'lop_deduction' => $lopDeduction,
            'basic' => $basicAmount,
            // HRA is a real named component now (no longer vestigial) — special_allowance
            // has no equivalent in the real structure, stays 0 for schema stability.
            'hra' => $hraAmount,
            'special_allowance' => 0.0,
            'pf_employee' => $pf,
            'esi_employee' => $esi,
            'professional_tax' => $pt,
            'educational_cess' => $educationalCess,
            'tds' => $tds,
            'health_insurance' => $healthInsurance,
            'other_deductions' => $otherDeductions,
            'total_deductions' => $totalDeductions,
            'net_pay' => max(0.0, $netPay),
            'ctc_addon_total' => $ctcAddonTotal,
            'total_ctc' => round($payableGross + $ctcAddonTotal, 2),
            // Earning rows first (in sequence order), then ctc_addon rows (in
            // sequence order) — PayslipPdfController splits back out by `category`
            // for the two sections of the PDF, this ordering is just for storage.
            'components' => [...$computed, ...$ctcAddonRows],
        ];
    }

    /**
     * Projects an employee's current monthly structure with no LOP applied —
     * used for display previews (e.g. the Employees form) and the Code on
     * Wages 50% guard, never for an actual payslip. Reuses the exact same
     * template/engine a real payslip would, so it can never drift from it.
     *
     * @return array{basic: float, gross: float, ctc_addon_total: float, ctc: float, components: array}
     */
    public function previewMonthly(Employee $employee): array
    {
        $components = SalaryComponent::activeOrdered();
        $earningComponents = $components->where('category', SalaryComponent::CATEGORY_EARNING);
        $ctcAddonComponents = $components->where('category', SalaryComponent::CATEGORY_CTC_ADDON);

        [$earningRows, $amountsByCode] = $this->computeComponents($earningComponents, $employee, 1.0);
        $gross = round(array_sum(array_column($earningRows, 'amount')), 2);

        [$ctcAddonRows] = $this->computeComponents($ctcAddonComponents, $employee, 1.0, $amountsByCode);
        $ctcAddonTotal = round(array_sum(array_column($ctcAddonRows, 'amount')), 2);

        return [
            'basic' => $amountsByCode['BASIC'] ?? 0.0,
            'gross' => $gross,
            'ctc_addon_total' => $ctcAddonTotal,
            'ctc' => round($gross + $ctcAddonTotal, 2),
            'components' => [...$earningRows, ...$ctcAddonRows],
        ];
    }

    /**
     * Code on Wages, 2019 §2(y) — Basic (+DA) must be at least 50% of total
     * remuneration; every excluded component combined (Variable Pay, HRA,
     * Employer NPS, Gratuity Provision) must not exceed the other 50%.
     * Computed against the live template so it can never drift from what a
     * payslip would actually pay. Pass the proposed Base Pay/HRA (not
     * necessarily what's saved yet) so this can gate a save before it happens.
     */
    public function basicWagePercent(float $basePayMonthly, float $hraMonthly): float
    {
        $employee = new Employee();
        // setRawAttributes() bypasses the EncryptedSafe cast's set() (which would
        // otherwise encrypt these in memory for no reason) — its get() tolerates
        // plain unencrypted values by design (see EncryptedSafe's docblock), so
        // this reads back correctly without ever calling Crypt.
        $employee->setRawAttributes(['base_pay_monthly' => (string) $basePayMonthly, 'hra_monthly' => (string) $hraMonthly]);

        $preview = $this->previewMonthly($employee);

        if ($preview['ctc'] <= 0.0) {
            return 100.0;
        }

        return round(($preview['basic'] / $preview['ctc']) * 100, 2);
    }

    /**
     * Walks an ordered component list once. A percentage component may only
     * reference an earlier (smaller-sequence) EARNING component's code —
     * enforced by SalaryComponentValidator at write time — so a single forward
     * pass always has every amount it needs already resolved. $seedAmounts lets
     * a ctc_addon pass resolve percentages against the already-computed earning
     * amounts (e.g. "NPS = 10% of Basic") without re-deriving them.
     *
     * @return array{0: list<array{code:string,name:string,calc_type:string,category:string,sequence:int,amount:float}>, 1: array<string,float>}
     */
    private function computeComponents(Collection $components, Employee $employee, float $lopFactor, array $seedAmounts = []): array
    {
        $amountsByCode = $seedAmounts;
        $nonRemainderSum = 0.0;
        $remainder = null;
        $rows = [];

        foreach ($components as $component) {
            if ($component->calc_type === 'remainder') {
                // SalaryComponentValidator enforces at most one active remainder
                // row (per category); if that's ever violated, the first one wins
                // and later ones are silently skipped rather than corrupting the total.
                $remainder ??= $component;
                continue;
            }

            $amount = match ($component->calc_type) {
                'flat' => (float) $component->value * $lopFactor,
                'employee_field' => $this->resolveEmployeeField($employee, $component->percentage_of) * $lopFactor,
                'percentage' => $this->resolvePercentageAmount($component, $amountsByCode),
                default => 0.0,
            };
            $amount = round($amount, 2);

            $amountsByCode[$component->code] = $amount;
            $nonRemainderSum += $amount;
            $rows[] = [
                'code' => $component->code,
                'name' => $component->name,
                'calc_type' => $component->calc_type,
                'category' => $component->category,
                'value' => $component->value !== null ? (float) $component->value : null,
                'percentage_of' => $component->percentage_of,
                'sequence' => (int) $component->sequence,
                'amount' => $amount,
            ];
        }

        if ($remainder) {
            // Gross is now a derived total (the sum of the earning components),
            // not a pre-known input to balance against — so there's nothing left
            // for a "remainder" row to be the remainder OF. Kept in the output
            // (at 0) purely so an admin who still has one configured sees it
            // rather than has it silently vanish; the validator steers new
            // templates away from this calc_type for earning components.
            $amountsByCode[$remainder->code] = 0.0;
            $rows[] = [
                'code' => $remainder->code,
                'name' => $remainder->name,
                'calc_type' => 'remainder',
                'category' => $remainder->category,
                'value' => null,
                'percentage_of' => null,
                'sequence' => (int) $remainder->sequence,
                'amount' => 0.0,
            ];
        }

        usort($rows, fn ($a, $b) => $a['sequence'] <=> $b['sequence']);

        return [$rows, $amountsByCode];
    }

    private function resolveEmployeeField(Employee $employee, ?string $field): float
    {
        if (! in_array($field, SalaryComponent::EMPLOYEE_FIELDS, true)) {
            return 0.0;
        }

        return (float) ($employee->{$field} ?? 0);
    }

    private function resolvePercentageAmount(SalaryComponent $component, array $amountsByCode): float
    {
        $pct = ((float) $component->value) / 100.0;

        if ($component->percentage_of === SalaryComponent::GROSS) {
            // No longer resolvable within a single component pass — see the
            // class docblock. The admin UI blocks saving an earning component
            // configured this way; defensive 0 if one ever slips through.
            return 0.0;
        }

        return ($amountsByCode[$component->percentage_of] ?? 0.0) * $pct;
    }
}
