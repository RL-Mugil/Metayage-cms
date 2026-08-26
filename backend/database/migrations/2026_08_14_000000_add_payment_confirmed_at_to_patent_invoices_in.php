<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The exact "payment made" timestamp for a renewal invoice — set by
// RenewalActionController::confirmReceipt(). Feeds the E-Register renewal
// table's CBR Date / Date of Renewal columns (both are this same value, per
// the client's explicit instruction — not separately tracked).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patent_invoices_in', function (Blueprint $table) {
            $table->timestamp('payment_confirmed_at')->nullable()->after('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('patent_invoices_in', function (Blueprint $table) {
            $table->dropColumn('payment_confirmed_at');
        });
    }
};
