<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Indian Patents (UIN) register becomes the portal's single source of truth for
 * invoices and quotes. Records raised in Zoho Books are imported into it (source = zoho,
 * linked by zoho_invoice_id); every dashboard reads the register.
 *
 * A Zoho record may not match a case (or even a client) yet, so project_id, client_id and
 * docket_number become optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patent_invoices_in', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->change();
            $table->foreignId('client_id')->nullable()->change();
            $table->string('docket_number', 70)->nullable()->change();

            $table->string('source', 10)->default('portal')->after('status');      // portal | zoho
            $table->foreignId('zoho_invoice_id')->nullable()->unique()->after('source')->constrained('zoho_invoices')->nullOnDelete();
            $table->string('zoho_number', 80)->nullable()->after('zoho_invoice_id');
            $table->string('zoho_status', 30)->nullable()->after('zoho_number');
            $table->date('due_date')->nullable()->after('invoice_date');
            $table->decimal('exchange_rate', 15, 6)->nullable()->after('currency');
            $table->decimal('total_bcy', 15, 2)->nullable()->after('exchange_rate');   // total in INR
            $table->decimal('balance_due', 15, 2)->nullable()->after('total_bcy');      // in the invoice currency
            $table->boolean('amount_split_pending')->default(false)->after('balance_due');
            $table->index(['source', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('patent_invoices_in', function (Blueprint $table) {
            $table->dropIndex(['source', 'type']);
            $table->dropConstrainedForeignId('zoho_invoice_id');
            $table->dropColumn(['source', 'zoho_number', 'zoho_status', 'due_date', 'exchange_rate', 'total_bcy', 'balance_due', 'amount_split_pending']);
        });
    }
};
