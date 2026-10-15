<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Models\SalaryComponent;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PayrollService computes from the admin-editable salary_components template (default: Basic = Base Pay,
 * Variable Pay = 25% of Basic, HRA = declared amount, so Gross = 1.25 x Base + HRA). Professional Tax comes from
 * system_settings (default: nil up to 25,000, then 200, 300 in February). PF and ESI are switched off in the engine
 * (currentPfEnabled()/currentEsiEnabled()), and PF additionally needs a UAN; these tests pin that behaviour.
 */
class PayrollServiceTest extends TestCase
{
    use RefreshDatabase;

    private PayrollService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PayrollService();
    }

    private function employee(float $base, float $hra = 0.0, ?string $uan = null): Employee
    {
        $employee = new Employee();
        $employee->setRawAttributes(['base_pay_monthly' => (string) $base, 'hra_monthly' => (string) $hra, 'uan_pf_number' => $uan]);

        return $employee;
    }

    private function slip(Employee $employee, float $lop = 0, float $tds = 0, int $days = 30, int $month = 6, bool $ptExempt = false): array
    {
        return $this->svc->computeSlip($employee, $lop, $tds, $days, $month, SalaryComponent::activeOrdered(), $ptExempt);
    }

    private function earningsTotal(array $slip): float
    {
        return round(array_sum(array_map(
            fn ($c) => $c['category'] === SalaryComponent::CATEGORY_EARNING ? $c['amount'] : 0.0,
            $slip['components']
        )), 2);
    }

    public function test_standard_salary_gross_and_professional_tax(): void
    {
        // Base 40,000 + Variable 25% (10,000) + HRA 10,000 = 60,000 gross.
        $slip = $this->slip($this->employee(40000, 10000));

        $this->assertSame(60000.0, $slip['monthly_gross']);
        $this->assertSame(60000.0, $slip['payable_gross']);
        $this->assertSame(40000.0, $slip['basic']);
        $this->assertSame(10000.0, $slip['hra']);
        $this->assertSame(200.0, $slip['professional_tax']);
        $this->assertSame(200.0, $slip['total_deductions']);
        $this->assertSame(59800.0, $slip['net_pay']);
    }

    public function test_pf_and_esi_are_off_even_for_low_salary_or_a_uan_holder(): void
    {
        $slip = $this->slip($this->employee(15000, 0, 'UAN100200300'));

        $this->assertSame(18750.0, $slip['payable_gross']); // under the 21,000 ESI limit, so ESI would apply if enabled
        $this->assertSame(0.0, $slip['pf_employee']);
        $this->assertSame(0.0, $slip['esi_employee']);
        $this->assertSame(0.0, $slip['professional_tax']); // gross <= 25,000 -> nil
        $this->assertSame(18750.0, $slip['net_pay']);
    }

    public function test_february_uses_the_february_professional_tax_amount(): void
    {
        $this->assertSame(300.0, $this->slip($this->employee(40000, 10000), month: 2, days: 28)['professional_tax']);
        $this->assertSame(0.0, $this->slip($this->employee(40000, 10000), ptExempt: true)['professional_tax']);
    }

    public function test_lop_prorates_every_earning_and_reports_the_deduction(): void
    {
        // 15 LOP days of 30 -> half of the 60,000 gross is payable.
        $slip = $this->slip($this->employee(40000, 10000), lop: 15);

        $this->assertSame(60000.0, $slip['monthly_gross']);
        $this->assertSame(30000.0, $slip['payable_gross']);
        $this->assertSame(30000.0, $slip['lop_deduction']);
        $this->assertSame(20000.0, $slip['basic']);
        $this->assertSame(5000.0, $slip['hra']);
        $this->assertSame(200.0, $slip['professional_tax']); // 30,000 payable is still above the nil threshold
    }

    public function test_full_month_lop_produces_zero_pay_and_no_professional_tax(): void
    {
        $slip = $this->slip($this->employee(40000, 10000), lop: 31, days: 31);

        $this->assertSame(0.0, $slip['payable_gross']);
        $this->assertSame(60000.0, $slip['lop_deduction']);
        $this->assertSame(0.0, $slip['basic']);
        $this->assertSame(0.0, $slip['professional_tax']);
        $this->assertSame(0.0, $slip['net_pay']);
    }

    public function test_lop_days_are_clamped_to_the_month_and_negative_tds_is_ignored(): void
    {
        $this->assertSame(30.0, $this->slip($this->employee(30000), lop: 99)['lop_days']);

        $neg = $this->slip($this->employee(30000), lop: -5, tds: -100);
        $this->assertSame(0.0, $neg['lop_days']);
        $this->assertSame(0.0, $neg['tds']);
    }

    public function test_tds_and_its_four_percent_cess_reduce_net_pay(): void
    {
        $employee = $this->employee(40000, 10000);
        $base = $this->slip($employee);
        $withTds = $this->slip($employee, tds: 7500);

        $this->assertSame(7500.0, $withTds['tds']);
        $this->assertSame(300.0, $withTds['educational_cess']);
        $this->assertSame(round($base['net_pay'] - 7500.0 - 300.0, 2), $withTds['net_pay']);
    }

    public function test_earning_components_always_sum_to_payable_gross_and_net_is_gross_minus_deductions(): void
    {
        foreach ([[33333, 2000, 2.5], [54321, 0, 0], [21000, 500, 1], [99999, 12000, 11.5]] as [$base, $hra, $lop]) {
            $slip = $this->slip($this->employee((float) $base, (float) $hra), lop: (float) $lop, days: 31);

            $this->assertEqualsWithDelta($slip['payable_gross'], $this->earningsTotal($slip), 0.011, "base={$base} lop={$lop}");
            $this->assertEqualsWithDelta($slip['payable_gross'] - $slip['total_deductions'], $slip['net_pay'], 0.011, "base={$base} lop={$lop}");
            $this->assertEqualsWithDelta($slip['monthly_gross'] - $slip['lop_deduction'], $slip['payable_gross'], 0.011);
        }
    }
}
