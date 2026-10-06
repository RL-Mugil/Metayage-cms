<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** HR-approved mirrors of the 8 new declared fields — see the sibling migration on investment_declarations. */
    public function up(): void
    {
        Schema::table('proof_of_investments', function (Blueprint $table) {
            if (! Schema::hasColumn('proof_of_investments', 'approved_lta_exemption_claimed')) {
                $table->decimal('approved_lta_exemption_claimed', 12, 2)->nullable()->after('approved_previous_employer_salary');
            }
            if (! Schema::hasColumn('proof_of_investments', 'approved_section_80gg_amount')) {
                $table->decimal('approved_section_80gg_amount', 12, 2)->nullable()->after('approved_lta_exemption_claimed');
            }
            if (! Schema::hasColumn('proof_of_investments', 'approved_section_80dd_amount')) {
                $table->decimal('approved_section_80dd_amount', 12, 2)->nullable()->after('approved_section_80gg_amount');
            }
            if (! Schema::hasColumn('proof_of_investments', 'approved_section_80ddb_amount')) {
                $table->decimal('approved_section_80ddb_amount', 12, 2)->nullable()->after('approved_section_80dd_amount');
            }
            if (! Schema::hasColumn('proof_of_investments', 'approved_section_80ee_amount')) {
                $table->decimal('approved_section_80ee_amount', 12, 2)->nullable()->after('approved_section_80ddb_amount');
            }
            if (! Schema::hasColumn('proof_of_investments', 'approved_section_80eea_amount')) {
                $table->decimal('approved_section_80eea_amount', 12, 2)->nullable()->after('approved_section_80ee_amount');
            }
            if (! Schema::hasColumn('proof_of_investments', 'approved_section_80gga_amount')) {
                $table->decimal('approved_section_80gga_amount', 12, 2)->nullable()->after('approved_section_80eea_amount');
            }
            if (! Schema::hasColumn('proof_of_investments', 'approved_section_80ggc_amount')) {
                $table->decimal('approved_section_80ggc_amount', 12, 2)->nullable()->after('approved_section_80gga_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('proof_of_investments', function (Blueprint $table) {
            $table->dropColumn([
                'approved_lta_exemption_claimed', 'approved_section_80gg_amount', 'approved_section_80dd_amount',
                'approved_section_80ddb_amount', 'approved_section_80ee_amount', 'approved_section_80eea_amount',
                'approved_section_80gga_amount', 'approved_section_80ggc_amount',
            ]);
        });
    }
};
