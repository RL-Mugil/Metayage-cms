<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** HR-approved mirrors of the 5 new declared fields — see the sibling migration on investment_declarations. */
    public function up(): void
    {
        Schema::table('proof_of_investments', function (Blueprint $table) {
            if (! Schema::hasColumn('proof_of_investments', 'approved_section_80ccd1b_amount')) {
                $table->decimal('approved_section_80ccd1b_amount', 12, 2)->nullable()->after('approved_section_80d_amount');
            }
            if (! Schema::hasColumn('proof_of_investments', 'approved_section_80e_amount')) {
                $table->decimal('approved_section_80e_amount', 12, 2)->nullable()->after('approved_section_80ccd1b_amount');
            }
            if (! Schema::hasColumn('proof_of_investments', 'approved_section_80g_amount')) {
                $table->decimal('approved_section_80g_amount', 12, 2)->nullable()->after('approved_section_80e_amount');
            }
            if (! Schema::hasColumn('proof_of_investments', 'approved_section_80tta_amount')) {
                $table->decimal('approved_section_80tta_amount', 12, 2)->nullable()->after('approved_section_80g_amount');
            }
            if (! Schema::hasColumn('proof_of_investments', 'approved_previous_employer_salary')) {
                $table->decimal('approved_previous_employer_salary', 12, 2)->nullable()->after('approved_other_income_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('proof_of_investments', function (Blueprint $table) {
            $table->dropColumn([
                'approved_section_80ccd1b_amount', 'approved_section_80e_amount', 'approved_section_80g_amount',
                'approved_section_80tta_amount', 'approved_previous_employer_salary',
            ]);
        });
    }
};
