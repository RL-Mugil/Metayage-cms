<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The remaining employee-declarable Form 16 Part B items, verified against
     * the Income Tax Act 2025 (as amended by FA Act 2026) primary text:
     * LTA/travel concession (§11 + Schedule III Sl.8), §80DD-equivalent disabled
     * dependant (§127), §80DDB-equivalent specified disease (§128), §80EE/§80EEA
     * legacy first-time-buyer home loan interest (§130/§131 — time-barred
     * eligibility windows, kept for employees still repaying a qualifying loan),
     * §80GG-equivalent rent paid with no HRA (§134), §80GGA research/rural
     * donations (§135), §80GGC political party donations (§137).
     */
    public function up(): void
    {
        Schema::table('investment_declarations', function (Blueprint $table) {
            if (! Schema::hasColumn('investment_declarations', 'lta_exemption_claimed')) {
                $table->decimal('lta_exemption_claimed', 12, 2)->nullable()->after('previous_employer_salary');
            }
            if (! Schema::hasColumn('investment_declarations', 'section_80gg_amount')) {
                $table->decimal('section_80gg_amount', 12, 2)->nullable()->after('lta_exemption_claimed');
            }
            if (! Schema::hasColumn('investment_declarations', 'section_80dd_amount')) {
                $table->decimal('section_80dd_amount', 12, 2)->nullable()->after('section_80gg_amount');
            }
            if (! Schema::hasColumn('investment_declarations', 'section_80ddb_amount')) {
                $table->decimal('section_80ddb_amount', 12, 2)->nullable()->after('section_80dd_amount');
            }
            if (! Schema::hasColumn('investment_declarations', 'section_80ee_amount')) {
                $table->decimal('section_80ee_amount', 12, 2)->nullable()->after('section_80ddb_amount');
            }
            if (! Schema::hasColumn('investment_declarations', 'section_80eea_amount')) {
                $table->decimal('section_80eea_amount', 12, 2)->nullable()->after('section_80ee_amount');
            }
            if (! Schema::hasColumn('investment_declarations', 'section_80gga_amount')) {
                $table->decimal('section_80gga_amount', 12, 2)->nullable()->after('section_80eea_amount');
            }
            if (! Schema::hasColumn('investment_declarations', 'section_80ggc_amount')) {
                $table->decimal('section_80ggc_amount', 12, 2)->nullable()->after('section_80gga_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('investment_declarations', function (Blueprint $table) {
            $table->dropColumn([
                'lta_exemption_claimed', 'section_80gg_amount', 'section_80dd_amount', 'section_80ddb_amount',
                'section_80ee_amount', 'section_80eea_amount', 'section_80gga_amount', 'section_80ggc_amount',
            ]);
        });
    }
};
