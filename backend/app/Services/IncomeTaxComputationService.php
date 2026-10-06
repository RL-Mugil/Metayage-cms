<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\InvestmentDeclaration;
use App\Models\PayrollRun;
use App\Models\ProofOfInvestment;
use App\Models\TaxSlabConfig;
use Illuminate\Support\Carbon;

/**
 * This is the honest, achievable half of "our own Form 16": a real annual
 * tax computation engine (Part B shape), NOT a legally valid Form 16 on its
 * own — since May 2019 only TRACES can issue a valid certificate (see
 * Form16Controller's docblock). Drives the Part B PDF and the monthly TDS
 * suggestion; never claims to replace the TRACES-issued Part A.
 */
class IncomeTaxComputationService
{
    public function computeForEmployee(Employee $employee, int $financialYear): array
    {
        $fyStart = Carbon::create($financialYear, 4, 1)->startOfDay();
        $fyEnd = Carbon::create($financialYear + 1, 3, 31)->endOfDay();

        $slips = PayrollRun::whereBetween('period', [$fyStart->toDateString(), $fyEnd->toDateString()])
            ->with(['payslips' => fn ($q) => $q->where('employee_id', $employee->id)->with('components')])
            ->get()
            ->flatMap(fn ($run) => $run->payslips);

        $grossAnnual = round((float) $slips->sum(fn ($s) => (float) $s->gross_salary - (float) $s->lop_deduction), 2);
        $tdsAlreadyWithheld = round((float) $slips->sum('tds'), 2);
        $professionalTaxAnnual = round((float) $slips->sum('professional_tax'), 2);

        $basicAnnual = round((float) $slips->sum(fn ($s) => (float) ($s->components->firstWhere('code', 'BASIC')?->amount ?? 0)), 2);
        $hraReceivedAnnual = round((float) $slips->sum(fn ($s) => (float) ($s->components->firstWhere('code', 'HRA')?->amount ?? 0)), 2);

        $declaration = InvestmentDeclaration::where('employee_id', $employee->id)
            ->where('financial_year', $financialYear)
            ->where('status', InvestmentDeclaration::STATUS_APPROVED)
            ->first();

        // Proof of Investments — a separate, later review phase. Pre-lock-in
        // ("Consider for IT"), the declared figures above remain provisional;
        // post-lock-in, each approved_* figure takes over (an explicit 0 from a
        // rejection is honored; null means "not reviewed" and falls back to
        // declared). See ProofOfInvestmentController.
        $poi = ProofOfInvestment::where('employee_id', $employee->id)
            ->where('financial_year', $financialYear)
            ->first();
        $considered = (bool) ($poi && $poi->isConsidered());
        $pick = fn ($approved, $declared) => $considered && $approved !== null ? (float) $approved : (float) ($declared ?? 0);

        $regime = $declaration->regime_choice ?? 'new';
        $housingType = $declaration->housing_type ?? 'rent'; // legacy declarations predate this field — default to the original rent-only behavior
        $rentPaidAnnual = $pick($poi?->approved_hra_rent_paid, $declaration->hra_rent_paid ?? 0);
        $homeLoanInterestAnnual = $pick($poi?->approved_home_loan_interest_amount, $declaration->home_loan_interest_declared ?? 0);
        $otherIncomeAnnual = $pick($poi?->approved_other_income_amount, $declaration->other_income_declared ?? 0);
        $section80c = $regime === 'old' ? min($pick($poi?->approved_section_80c_amount, $declaration->section_80c_amount ?? 0), 150000) : 0.0;
        $section80d = $regime === 'old' ? min($pick($poi?->approved_section_80d_amount, $declaration->section_80d_amount ?? 0), 100000) : 0.0;
        $section80ccd1b = $regime === 'old' ? min($pick($poi?->approved_section_80ccd1b_amount, $declaration->section_80ccd1b_amount ?? 0), 50000) : 0.0;
        $section80e = $regime === 'old' ? $pick($poi?->approved_section_80e_amount, $declaration->section_80e_amount ?? 0) : 0.0;
        $section80g = $regime === 'old' ? $pick($poi?->approved_section_80g_amount, $declaration->section_80g_amount ?? 0) : 0.0;
        $section80tta = $regime === 'old' ? min($pick($poi?->approved_section_80tta_amount, $declaration->section_80tta_amount ?? 0), 10000) : 0.0;
        // Salary from a previous employer this FY (Form 16B item 1(e)) — a salary
        // fact, not a deduction, so it applies under both regimes.
        $previousEmployerSalary = $pick($poi?->approved_previous_employer_salary, $declaration->previous_employer_salary ?? 0);

        // Remaining employee-declarable items, all old-regime-only (new regime
        // §202(2)(a) excludes Schedule III Sl.8/11 and "Chapter VIII other than
        // §124(1)/(2)/§125(2)/§146"), with the same declared/approved pick pattern.
        $ltaExemption = $regime === 'old' ? $pick($poi?->approved_lta_exemption_claimed, $declaration->lta_exemption_claimed ?? 0) : 0.0;
        $section80dd = $regime === 'old' ? min($pick($poi?->approved_section_80dd_amount, $declaration->section_80dd_amount ?? 0), 125000) : 0.0;
        $section80ddb = $regime === 'old' ? min($pick($poi?->approved_section_80ddb_amount, $declaration->section_80ddb_amount ?? 0), 100000) : 0.0;
        $section80ee = $regime === 'old' ? min($pick($poi?->approved_section_80ee_amount, $declaration->section_80ee_amount ?? 0), 50000) : 0.0;
        $section80eea = $regime === 'old' ? min($pick($poi?->approved_section_80eea_amount, $declaration->section_80eea_amount ?? 0), 150000) : 0.0;
        $section80gga = $regime === 'old' ? $pick($poi?->approved_section_80gga_amount, $declaration->section_80gga_amount ?? 0) : 0.0;
        $section80ggc = $regime === 'old' ? $pick($poi?->approved_section_80ggc_amount, $declaration->section_80ggc_amount ?? 0) : 0.0;

        // §80GG-equivalent (rent paid, no HRA component) — only applicable when the
        // employee receives no HRA at all; auto-gated rather than a separate toggle.
        // Capped at the minimum of: rent paid less 10% of income, ₹5,000/month
        // (₹60,000/yr), or 25% of income — same 3-way formula as the Act's own §134(2).
        $section80ggRentPaid = $pick($poi?->approved_section_80gg_amount, $declaration->section_80gg_amount ?? 0);
        $section80gg = 0.0;
        if ($regime === 'old' && $hraReceivedAnnual <= 0 && $section80ggRentPaid > 0) {
            $incomeBeforeThisDeduction = $grossAnnual + $previousEmployerSalary + $otherIncomeAnnual;
            $section80gg = max(0.0, min(
                $section80ggRentPaid - (0.10 * $incomeBeforeThisDeduction),
                60000.0,
                0.25 * $incomeBeforeThisDeduction,
            ));
        }
        $section80gg = round($section80gg, 2);

        // Housing benefit is mutually exclusive — either HRA exemption (renting)
        // or home-loan-interest deduction under §24(b) (self-occupied, capped
        // ₹2,00,000), never both.
        $hraExemption = 0.0;
        $homeLoanInterestDeduction = 0.0;
        if ($regime === 'old' && $housingType === 'self_occupied_loan') {
            $homeLoanInterestDeduction = min($homeLoanInterestAnnual, 200000.0);
        } elseif ($regime === 'old' && $hraReceivedAnnual > 0 && $rentPaidAnnual > 0) {
            $hraExemption = max(0.0, min(
                $hraReceivedAnnual,
                $rentPaidAnnual - (0.10 * $basicAnnual),
                0.50 * $basicAnnual, // metro-city formula; non-metro is 40% — not tracked per-employee today
            ));
        }
        $hraExemption = round($hraExemption, 2);
        $homeLoanInterestDeduction = round($homeLoanInterestDeduction, 2);
        $housingBenefit = round($hraExemption + $homeLoanInterestDeduction, 2);

        $config = TaxSlabConfig::where('financial_year', $financialYear)->where('regime', $regime)->first();
        if (! $config) {
            // No slab config for this FY/regime yet (admin hasn't added it in Settings) —
            // fail honestly rather than silently computing zero tax.
            return [
                'error' => "No tax slab configuration found for FY{$financialYear}-" . substr((string) ($financialYear + 1), -2) . " ({$regime} regime). Add one in Settings → Payroll → Taxes.",
            ];
        }

        $standardDeduction = (float) $config->standard_deduction;
        $chapterVIADeductions = round(
            $section80c + $section80ccd1b + $section80d + $section80e + $section80g + $section80tta
            + $section80dd + $section80ddb + $section80ee + $section80eea + $section80gg + $section80gga + $section80ggc,
            2
        );

        // Professional tax (section 16(iii)) is deductible only under the old
        // regime — the new regime (115BAC) allows just the standard deduction
        // u/s 16(ia), not entertainment allowance or professional tax.
        $section16iiiDeduction = $regime === 'old' ? $professionalTaxAnnual : 0.0;

        // Other income/investments increase liability (added to gross), unlike
        // the Chapter VI-A deductions and housing benefit which reduce it. Previous
        // employer salary joins current-employer salary before Chapter VI-A, same
        // point as Form 16B's own item 6 = "3+1(e)-5". LTA is a separate section-10-
        // style exemption from housing (not mutually exclusive with HRA/home loan
        // interest — an employee can claim both), so it's its own subtracted term.
        $taxableIncome = max(0.0, round(
            $grossAnnual + $previousEmployerSalary + $otherIncomeAnnual - $housingBenefit - $ltaExemption - $standardDeduction - $section16iiiDeduction - $chapterVIADeductions,
            2
        ));

        $slabTax = $this->computeSlabTax($taxableIncome, $config->slabs_json);

        $rebate = $taxableIncome <= (float) $config->rebate_87a_limit
            ? min($slabTax, (float) $config->rebate_87a_amount)
            : 0.0;
        $taxAfterRebate = max(0.0, round($slabTax - $rebate, 2));

        $cess = round($taxAfterRebate * ((float) $config->cess_rate / 100), 2);
        $annualTaxLiability = round($taxAfterRebate + $cess, 2);

        return [
            'financial_year' => $financialYear,
            'regime' => $regime,
            'gross_annual_salary' => $grossAnnual,
            'basic_annual' => $basicAnnual,
            'hra_received_annual' => $hraReceivedAnnual,
            'hra_exemption' => $hraExemption,
            'housing_type' => $housingType,
            'home_loan_interest_deduction' => $homeLoanInterestDeduction,
            'other_income_annual' => $otherIncomeAnnual,
            'previous_employer_salary' => $previousEmployerSalary,
            'lta_exemption' => $ltaExemption,
            'standard_deduction' => $standardDeduction,
            'professional_tax_annual' => $professionalTaxAnnual,
            'section16iii_deduction' => $section16iiiDeduction,
            'section_80c' => $section80c,
            'section_80ccd1b' => $section80ccd1b,
            'section_80d' => $section80d,
            'section_80e' => $section80e,
            'section_80g' => $section80g,
            'section_80tta' => $section80tta,
            'section_80dd' => $section80dd,
            'section_80ddb' => $section80ddb,
            'section_80ee' => $section80ee,
            'section_80eea' => $section80eea,
            'section_80gg' => $section80gg,
            'section_80gga' => $section80gga,
            'section_80ggc' => $section80ggc,
            'chapter_via_deductions' => $chapterVIADeductions,
            'figures_basis' => $considered ? 'considered' : 'provisional',
            'taxable_income' => $taxableIncome,
            'slab_tax' => round($slabTax, 2),
            'rebate_87a' => round($rebate, 2),
            'tax_after_rebate' => $taxAfterRebate,
            'cess' => $cess,
            'annual_tax_liability' => $annualTaxLiability,
            'tds_already_withheld' => $tdsAlreadyWithheld,
            'balance_tax_payable' => round($annualTaxLiability - $tdsAlreadyWithheld, 2),
        ];
    }

    /** Ordered [{upto, rate}] slabs — `upto: null` means the top, unbounded bracket. */
    private function computeSlabTax(float $taxableIncome, array $slabs): float
    {
        $tax = 0.0;
        $prevUpto = 0.0;

        foreach ($slabs as $slab) {
            $upto = $slab['upto'] ?? null;
            $bracketTop = $upto === null ? $taxableIncome : (float) $upto;

            if ($taxableIncome <= $prevUpto) {
                break;
            }

            $portion = min($taxableIncome, $bracketTop) - $prevUpto;
            $tax += $portion * ((float) $slab['rate'] / 100);
            $prevUpto = $bracketTop;

            if ($upto !== null && $taxableIncome <= $upto) {
                break;
            }
        }

        return $tax;
    }
}
