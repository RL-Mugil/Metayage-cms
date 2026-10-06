<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Reproduces PayrollService's old hardcoded formula exactly (Basic = 50% of
     * gross, HRA = 50% of Basic, Special Allowance = remainder) so the first
     * payroll run generated after this ships computes identically to the day
     * before — zero numeric regression until an admin actually edits the template.
     */
    public function up(): void
    {
        if (DB::table('salary_components')->count() > 0) {
            return;
        }

        $now = now();
        DB::table('salary_components')->insert([
            [
                'name' => 'Basic', 'code' => 'BASIC', 'calc_type' => 'percentage',
                'value' => 50, 'percentage_of' => '_GROSS_', 'sequence' => 1, 'active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'HRA', 'code' => 'HRA', 'calc_type' => 'percentage',
                'value' => 50, 'percentage_of' => 'BASIC', 'sequence' => 2, 'active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'Special Allowance', 'code' => 'SPECIAL_ALLOWANCE', 'calc_type' => 'remainder',
                'value' => null, 'percentage_of' => null, 'sequence' => 3, 'active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('salary_components')->whereIn('code', ['BASIC', 'HRA', 'SPECIAL_ALLOWANCE'])->delete();
    }
};
