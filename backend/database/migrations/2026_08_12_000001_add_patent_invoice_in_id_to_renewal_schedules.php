<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Links a renewal-year row to the PatentInvoiceIn record raised for it, so
// multiple renewal years can share one invoice (client renews 5 years at once).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('renewal_schedules', function (Blueprint $table) {
            $table->foreignId('patent_invoice_in_id')->nullable()->after('patent_application_id')
                ->constrained('patent_invoices_in')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('renewal_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('patent_invoice_in_id');
        });
    }
};
