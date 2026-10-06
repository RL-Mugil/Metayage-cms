<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshots enough of the source SalaryComponent row to render an accurate
     * "(25% from Basic Pay)" style label on a PDF even after the live template
     * changes later — without these, an old payslip's label would silently
     * drift if the admin edits/renames the template afterward.
     */
    public function up(): void
    {
        Schema::table('payslip_components', function (Blueprint $table) {
            if (! Schema::hasColumn('payslip_components', 'value')) {
                $table->decimal('value', 12, 2)->nullable()->after('category');
            }
            if (! Schema::hasColumn('payslip_components', 'percentage_of')) {
                $table->string('percentage_of')->nullable()->after('value');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payslip_components', function (Blueprint $table) {
            foreach (['value', 'percentage_of'] as $col) {
                if (Schema::hasColumn('payslip_components', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
