<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments received against register invoices, with their real payment dates (imported from
 * Zoho Books customer payments), and a key on client_ledger lines so the ledger can be
 * rebuilt from the register: invoice = debit, payment = credit, running balance by date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('register_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patent_invoice_in_id')->constrained('patent_invoices_in')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('zoho_payment_id', 80);          // "balance:<invoice>" when Zoho has no payment record
            $table->string('payment_number', 80)->nullable();
            $table->date('payment_date');
            $table->decimal('amount', 15, 2);                // in the invoice currency
            $table->string('currency', 5)->default('INR');
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->decimal('amount_bcy', 15, 2);            // INR
            $table->string('payment_mode', 60)->nullable();
            $table->string('reference', 120)->nullable();
            $table->boolean('estimated')->default(false);    // dated from Zoho's last payment date, no payment record
            $table->timestamps();
            $table->unique(['patent_invoice_in_id', 'zoho_payment_id']);
            $table->index(['payment_date']);
            $table->index(['zoho_payment_id']);
        });

        Schema::table('client_ledger', function (Blueprint $table) {
            $table->string('source_key', 120)->nullable()->unique()->after('document_reference');
        });
    }

    public function down(): void
    {
        Schema::table('client_ledger', function (Blueprint $table) {
            $table->dropUnique(['source_key']);
            $table->dropColumn('source_key');
        });
        Schema::dropIfExists('register_payments');
    }
};
