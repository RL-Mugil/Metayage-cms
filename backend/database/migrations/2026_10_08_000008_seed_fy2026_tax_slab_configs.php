<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // FY2026-27 slabs — admin-editable via Settings → Payroll → Taxes afterwards,
        // since these change every Union Budget (same philosophy as Professional Tax).
        DB::table('tax_slab_configs')->updateOrInsert(
            ['financial_year' => 2026, 'regime' => 'new'],
            [
                'slabs_json' => json_encode([
                    ['upto' => 400000, 'rate' => 0],
                    ['upto' => 800000, 'rate' => 5],
                    ['upto' => 1200000, 'rate' => 10],
                    ['upto' => 1600000, 'rate' => 15],
                    ['upto' => 2000000, 'rate' => 20],
                    ['upto' => 2400000, 'rate' => 25],
                    ['upto' => null, 'rate' => 30],
                ]),
                'standard_deduction' => 75000,
                'rebate_87a_limit' => 1200000,
                'rebate_87a_amount' => 60000,
                'cess_rate' => 4.00,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        DB::table('tax_slab_configs')->updateOrInsert(
            ['financial_year' => 2026, 'regime' => 'old'],
            [
                'slabs_json' => json_encode([
                    ['upto' => 250000, 'rate' => 0],
                    ['upto' => 500000, 'rate' => 5],
                    ['upto' => 1000000, 'rate' => 20],
                    ['upto' => null, 'rate' => 30],
                ]),
                'standard_deduction' => 50000,
                'rebate_87a_limit' => 500000,
                'rebate_87a_amount' => 12500,
                'cess_rate' => 4.00,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        DB::table('tax_slab_configs')->where('financial_year', 2026)->delete();
    }
};
