<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additional Form 16 Part B-declarable fields not previously captured:
     * section 80CCD(1B) (additional NPS), 80E (education loan interest),
     * 80G (donations), 80TTA (savings account interest), and salary from a
     * previous employer this FY (item 1(e)) — see IncomeTaxComputationService
     * and Form16Controller::buildPartBLineItems().
     */
    public function up(): void
    {
        Schema::table('investment_declarations', function (Blueprint $table) {
            if (! Schema::hasColumn('investment_declarations', 'section_80ccd1b_amount')) {
                $table->decimal('section_80ccd1b_amount', 12, 2)->nullable()->after('section_80d_amount');
            }
            if (! Schema::hasColumn('investment_declarations', 'section_80e_amount')) {
                $table->decimal('section_80e_amount', 12, 2)->nullable()->after('section_80ccd1b_amount');
            }
            if (! Schema::hasColumn('investment_declarations', 'section_80g_amount')) {
                $table->decimal('section_80g_amount', 12, 2)->nullable()->after('section_80e_amount');
            }
            if (! Schema::hasColumn('investment_declarations', 'section_80tta_amount')) {
                $table->decimal('section_80tta_amount', 12, 2)->nullable()->after('section_80g_amount');
            }
            if (! Schema::hasColumn('investment_declarations', 'previous_employer_salary')) {
                $table->decimal('previous_employer_salary', 12, 2)->nullable()->after('other_income_declared');
            }
        });
    }

    public function down(): void
    {
        Schema::table('investment_declarations', function (Blueprint $table) {
            $table->dropColumn([
                'section_80ccd1b_amount', 'section_80e_amount', 'section_80g_amount',
                'section_80tta_amount', 'previous_employer_salary',
            ]);
        });
    }
};
