<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Discount applies to the professional (service) fee only — government fees
// are statutory and never discounted. Zero when there's no discount. See
// PatentInvoiceController::computeTotals().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patent_invoices_in', function (Blueprint $table) {
            $table->decimal('discount_percentage', 5, 2)->default(0)->after('service_fees');
            $table->decimal('discount_amount', 15, 2)->default(0)->after('discount_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('patent_invoices_in', function (Blueprint $table) {
            $table->dropColumn(['discount_percentage', 'discount_amount']);
        });
    }
};
