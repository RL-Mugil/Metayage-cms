<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Replaces the placeholder 50/25/25 template with Metayage's real,
     * verified-from-payslips formula: Basic = declared Base Pay (an
     * `employee_field` component — see PayrollService/SalaryComponent),
     * Variable Pay = 25% of Basic, HRA = declared flat per-employee amount
     * (also `employee_field`), Employer NPS = 10% of Basic and Gratuity
     * Provision = Basic/24 (both `ctc_addon`, never reduce Net Pay). There's
     * no "remainder" bucket in the real structure, so the old Special
     * Allowance row is dropped — Gross is now simply the sum of the three
     * earning rows, not a pre-known input split three ways.
     *
     * `employee_field` components store which Employee column to read in the
     * `percentage_of` column (whitelisted in SalaryComponent::EMPLOYEE_FIELDS)
     * — `value` stays null for them, same shape the column already has.
     */
    public function up(): void
    {
        DB::table('salary_components')->whereIn('code', ['BASIC', 'HRA', 'SPECIAL_ALLOWANCE'])->delete();

        $now = now();
        DB::table('salary_components')->insert([
            [
                'name' => 'Basic', 'code' => 'BASIC', 'calc_type' => 'employee_field', 'category' => 'earning',
                'value' => null, 'percentage_of' => 'base_pay_monthly', 'sequence' => 1, 'active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'Variable Pay', 'code' => 'VARIABLE_PAY', 'calc_type' => 'percentage', 'category' => 'earning',
                'value' => 25, 'percentage_of' => 'BASIC', 'sequence' => 2, 'active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'HRA', 'code' => 'HRA', 'calc_type' => 'employee_field', 'category' => 'earning',
                'value' => null, 'percentage_of' => 'hra_monthly', 'sequence' => 3, 'active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'Employer NPS Contribution', 'code' => 'EMPLOYER_NPS', 'calc_type' => 'percentage', 'category' => 'ctc_addon',
                'value' => 10, 'percentage_of' => 'BASIC', 'sequence' => 4, 'active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'Gratuity Provision', 'code' => 'GRATUITY_PROVISION', 'calc_type' => 'percentage', 'category' => 'ctc_addon',
                // 1/24 = 4.1667%, confirmed against the payslip workbook (Base Pay ÷ 24) for 4 employees.
                'value' => 4.1667, 'percentage_of' => 'BASIC', 'sequence' => 5, 'active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('salary_components')->whereIn('code', ['BASIC', 'VARIABLE_PAY', 'HRA', 'EMPLOYER_NPS', 'GRATUITY_PROVISION'])->delete();

        $now = now();
        DB::table('salary_components')->insert([
            [
                'name' => 'Basic', 'code' => 'BASIC', 'calc_type' => 'percentage', 'category' => 'earning',
                'value' => 50, 'percentage_of' => '_GROSS_', 'sequence' => 1, 'active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'HRA', 'code' => 'HRA', 'calc_type' => 'percentage', 'category' => 'earning',
                'value' => 50, 'percentage_of' => 'BASIC', 'sequence' => 2, 'active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'Special Allowance', 'code' => 'SPECIAL_ALLOWANCE', 'calc_type' => 'remainder', 'category' => 'earning',
                'value' => null, 'percentage_of' => null, 'sequence' => 3, 'active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }
};
