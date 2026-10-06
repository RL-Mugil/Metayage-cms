<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `employees.salary` was always meant to be the declared monthly Base Pay —
     * PayrollService just used to derive the rest of the structure from it via
     * a generic 50/25/25 formula. Renaming it to say what it actually is, and
     * adding the two other genuinely independent per-employee inputs the real
     * firm structure needs: a flat monthly HRA, and a payroll-run opt-out flag.
     */
    public function up(): void
    {
        if (Schema::hasColumn('employees', 'salary') && ! Schema::hasColumn('employees', 'base_pay_monthly')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->renameColumn('salary', 'base_pay_monthly');
            });
        }

        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'hra_monthly')) {
                $table->text('hra_monthly')->nullable()->after('base_pay_monthly');
            }
            if (! Schema::hasColumn('employees', 'exclude_from_payroll')) {
                $table->boolean('exclude_from_payroll')->default(false)->after('professional_tax_exempt');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'exclude_from_payroll')) {
                $table->dropColumn('exclude_from_payroll');
            }
            if (Schema::hasColumn('employees', 'hra_monthly')) {
                $table->dropColumn('hra_monthly');
            }
        });

        if (Schema::hasColumn('employees', 'base_pay_monthly') && ! Schema::hasColumn('employees', 'salary')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->renameColumn('base_pay_monthly', 'salary');
            });
        }
    }
};
