<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Drives fee_rate_cards lookups for this client's quotes/invoices. Distinct
// from entity_type/entity_subtype (legal form: Pvt Ltd, LLP, ...) and
// gst_type (tax classification) — neither of those maps to a fee-discount
// tier. 2-tier by design (see plan): India's own Individual/Startup/MSME vs
// Large Entity split; for US invoices the discounted tier maps to Micro
// Entity rates (see FeeRateCardController / the seeded fee_rate_cards data).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('fee_entity_tier', 30)->nullable()->after('entity_subtype');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('fee_entity_tier');
        });
    }
};
