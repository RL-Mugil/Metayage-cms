<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_declarations', function (Blueprint $table) {
            // 'rent' or 'self_occupied_loan' — mutually exclusive with the existing
            // hra_rent_paid-only assumption; see IncomeTaxComputationService.
            if (! Schema::hasColumn('investment_declarations', 'housing_type')) {
                $table->string('housing_type')->nullable()->after('hra_rent_paid');
            }
            if (! Schema::hasColumn('investment_declarations', 'home_loan_interest_declared')) {
                $table->decimal('home_loan_interest_declared', 12, 2)->nullable()->after('housing_type');
            }
            if (! Schema::hasColumn('investment_declarations', 'other_income_declared')) {
                $table->decimal('other_income_declared', 12, 2)->nullable()->after('home_loan_interest_declared');
            }
        });
    }

    public function down(): void
    {
        Schema::table('investment_declarations', function (Blueprint $table) {
            $table->dropColumn(['housing_type', 'home_loan_interest_declared', 'other_income_declared']);
        });
    }
};
