<?php

namespace Tests\Unit;

use App\Services\PayrollService;
use PHPUnit\Framework\TestCase;

class PayrollServiceTest extends TestCase
{
    private PayrollService $svc;

    protected function setUp(): void
    {
        $this->svc = new PayrollService();
    }

    public function test_standard_salary_above_esi_limit(): void
    {
        // ₹1,00,000 gross: basic 50k, HRA 25k, special 25k.
        // PF capped at ceiling (basic 50k > 15k → 1800). No ESI above 21k gross.
        $slip = $this->svc->computeSlip(100000, 0, 0, 30);

        $this->assertSame(50000.0, $slip['basic']);
        $this->assertSame(25000.0, $slip['hra']);
        $this->assertSame(25000.0, $slip['special_allowance']);
        $this->assertSame(1800.0, $slip['pf_employee']);
        $this->assertSame(0.0, $slip['esi_employee']);
        $this->assertSame(200.0, $slip['professional_tax']);
        $this->assertSame(2000.0, $slip['total_deductions']);
        $this->assertSame(98000.0, $slip['net_pay']);
    }

    public function test_low_salary_attracts_esi_and_uncapped_pf(): void
    {
        // ₹20,000 gross: basic 10k → PF 12% = 1200 (below ceiling).
        // Gross ≤ 21k → ESI 0.75% of 20k = 150.
        $slip = $this->svc->computeSlip(20000, 0, 0, 30);

        $this->assertSame(10000.0, $slip['basic']);
        $this->assertSame(1200.0, $slip['pf_employee']);
        $this->assertSame(150.0, $slip['esi_employee']);
        $this->assertSame(200.0, $slip['professional_tax']);
        $this->assertSame(20000.0 - 1550.0, $slip['net_pay']);
    }

    public function test_lop_prorates_gross_before_structure(): void
    {
        // 15 LOP days of 30 → half gross payable.
        $slip = $this->svc->computeSlip(60000, 15, 0, 30);

        $this->assertSame(30000.0, $slip['lop_deduction']);
        $this->assertSame(15000.0, $slip['basic']);
        // Payable gross 30k > 21k → still no ESI.
        $this->assertSame(0.0, $slip['esi_employee']);
        $this->assertSame(1800.0, $slip['pf_employee']); // basic 15000 → exactly at ceiling
    }

    public function test_full_month_lop_produces_zero_payable(): void
    {
        $slip = $this->svc->computeSlip(50000, 31, 0, 31);

        $this->assertSame(50000.0, $slip['lop_deduction']);
        $this->assertSame(0.0, $slip['basic']);
        $this->assertSame(0.0, $slip['professional_tax']); // no PT on zero pay
        $this->assertSame(0.0, $slip['net_pay']);
    }

    public function test_lop_days_clamped_to_month_and_negatives_rejected(): void
    {
        $over = $this->svc->computeSlip(30000, 99, 0, 30);
        $this->assertSame(30.0, $over['lop_days']);

        $neg = $this->svc->computeSlip(30000, -5, -100, 30);
        $this->assertSame(0.0, $neg['lop_days']);
        $this->assertSame(0.0, $neg['tds']);
    }

    public function test_tds_reduces_net_pay(): void
    {
        $base = $this->svc->computeSlip(100000, 0, 0, 30);
        $withTds = $this->svc->computeSlip(100000, 0, 7500, 30);

        $this->assertSame(7500.0, $withTds['tds']);
        $this->assertSame($base['net_pay'] - 7500.0, $withTds['net_pay']);
    }

    public function test_components_always_sum_to_payable_gross(): void
    {
        foreach ([[33333, 2.5], [54321, 0], [21000, 1], [99999, 11.5]] as [$gross, $lop]) {
            $slip = $this->svc->computeSlip((float) $gross, (float) $lop, 0, 31);
            $payable = round($gross - $slip['lop_deduction'], 2);
            $this->assertEqualsWithDelta(
                $payable,
                $slip['basic'] + $slip['hra'] + $slip['special_allowance'],
                0.011,
                "Earnings must reconstruct payable gross for gross={$gross}, lop={$lop}"
            );
            $this->assertEqualsWithDelta(
                $payable - $slip['total_deductions'],
                $slip['net_pay'],
                0.011
            );
        }
    }
}
