<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Government + professional fee rate card, keyed by jurisdiction + service +
// client entity tier, driving auto-populated quote/invoice amounts and the
// renewal-approval flow's per-year-banded math. See
// app/Http/Controllers/FeeRateCardController.php and
// app/Http/Controllers/RenewalActionController.php::renewalTotals().
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_rate_cards', function (Blueprint $table) {
            $table->id();
            $table->string('jurisdiction', 5);              // IN | US | EP | WO — matches Project.patent_office_code
            $table->string('service_code', 10);              // matches config/project_import_codes.php 'services' keys
            $table->string('entity_tier', 20)->nullable();   // 'discounted' | 'standard' | NULL (universal — EP/PCT/no-tier rows)

            // Renewal / maintenance year-banding — NULL for ordinary one-off services.
            // decimal, not int, to hold US's 3.5 / 7.5 / 11.5-year maintenance milestones.
            $table->decimal('year_from', 4, 1)->nullable();
            $table->decimal('year_to', 4, 1)->nullable();

            $table->string('validation_country', 50)->nullable(); // EP validation-fee rows only

            $table->decimal('govt_fee_amount', 15, 2)->nullable();  // NULL = N.A. / blank / staff-fill / mixed-currency
            $table->string('govt_fee_currency', 6)->nullable();     // INR | USD | EUR | GBP | MIXED

            $table->decimal('professional_fee_amount', 15, 2)->nullable();
            $table->string('professional_fee_currency', 6)->nullable();
            $table->decimal('professional_fee_max_amount', 15, 2)->nullable(); // EP ranges — display-only upper bound
            // 'per_unit' = charged once per year/band-year (default, e.g. EP renewals);
            // 'flat_per_transaction' = charged once regardless of how many years are
            // approved together (IN renewal professional fee only).
            $table->string('professional_fee_charge_basis', 20)->default('per_unit');

            $table->json('fee_breakdown')->nullable(); // PCT's multi-currency, multi-component govt fee
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['jurisdiction', 'service_code', 'entity_tier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_rate_cards');
    }
};
