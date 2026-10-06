<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            if (! Schema::hasColumn('payslips', 'educational_cess')) {
                $table->decimal('educational_cess', 12, 2)->default(0)->after('professional_tax');
            }
            if (! Schema::hasColumn('payslips', 'health_insurance')) {
                $table->decimal('health_insurance', 12, 2)->default(0)->after('tds');
            }
            if (! Schema::hasColumn('payslips', 'other_deductions')) {
                $table->decimal('other_deductions', 12, 2)->default(0)->after('health_insurance');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            foreach (['educational_cess', 'health_insurance', 'other_deductions'] as $col) {
                if (Schema::hasColumn('payslips', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
