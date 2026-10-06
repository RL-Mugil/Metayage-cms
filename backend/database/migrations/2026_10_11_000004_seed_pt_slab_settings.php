<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Replaces the flat `payroll_pt_monthly` setting with a real Karnataka
     * Professional Tax slab (the firm's legal entity/TAN/ROC registration is
     * Karnataka/Bangalore, so this one slab applies firm-wide regardless of
     * where an employee physically works): gross ≤ nil threshold → Nil,
     * else the monthly amount, except February which is the february amount
     * (statutory annual cap ₹2,500 = 11×200+300).
     *
     * Variable Pay's 25% does NOT get its own system_settings key — it's
     * just the VARIABLE_PAY salary_component's own `value` (seeded in
     * 2026_10_11_000003), already editable firm-wide via the existing
     * Settings → Payroll salary-components editor like any other percentage
     * component. A second settings key for the same number would just be a
     * second source of truth that could drift from the one actually used.
     */
    public function up(): void
    {
        $now = now();

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'payroll_pt_nil_threshold'],
            ['value' => '25000', 'created_at' => $now, 'updated_at' => $now]
        );
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'payroll_pt_monthly_amount'],
            ['value' => '200', 'created_at' => $now, 'updated_at' => $now]
        );
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'payroll_pt_february_amount'],
            ['value' => '300', 'created_at' => $now, 'updated_at' => $now]
        );

        DB::table('system_settings')->where('key', 'payroll_pt_monthly')->delete();
    }

    public function down(): void
    {
        $now = now();
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'payroll_pt_monthly'],
            ['value' => '200', 'created_at' => $now, 'updated_at' => $now]
        );

        DB::table('system_settings')->whereIn('key', [
            'payroll_pt_nil_threshold',
            'payroll_pt_monthly_amount',
            'payroll_pt_february_amount',
        ])->delete();
    }
};
