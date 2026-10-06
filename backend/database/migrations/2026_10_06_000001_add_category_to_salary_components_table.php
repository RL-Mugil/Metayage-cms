<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 'earning' (default, today's behavior — flat/percentage/remainder rows that
     * sum to payable gross) vs 'ctc_addon' (new — employer-side cost like NPS or
     * Gratuity Provision that inflates the displayed Total CTC but never reduces
     * Net Pay, matching standard Indian payroll practice).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('salary_components', 'category')) {
            Schema::table('salary_components', function (Blueprint $table) {
                $table->string('category')->default('earning')->after('calc_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('salary_components', 'category')) {
            Schema::table('salary_components', function (Blueprint $table) {
                $table->dropColumn('category');
            });
        }
    }
};
