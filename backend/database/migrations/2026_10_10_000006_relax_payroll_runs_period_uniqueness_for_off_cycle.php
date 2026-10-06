<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The original schema made `period` globally unique, one run per month.
        // Off-Cycle runs (see PayrollController::store) must be able to coexist
        // with a Regular run — and with each other — in the same period, so the
        // constraint needs to become "only one Regular run per period".
        Schema::table('payroll_runs', function ($table) {
            $table->dropUnique('payroll_runs_period_unique');
        });

        DB::statement(
            "CREATE UNIQUE INDEX payroll_runs_period_regular_unique ON payroll_runs (period) WHERE run_type = 'Regular'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payroll_runs_period_regular_unique');

        Schema::table('payroll_runs', function ($table) {
            $table->unique('period');
        });
    }
};
