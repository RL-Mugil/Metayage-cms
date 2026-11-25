<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the firm pays out on a matter: official (Patent Office) fees, foreign associate bills and
 * other costs. Entered in the portal or imported from Zoho Books bills / expenses. Recoverable
 * costs are expected to be billed to the client; billed_invoice_id records the invoice that did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('kind', 20);                          // official_fee | associate | other
            $table->string('vendor_name', 255)->nullable();
            $table->string('vendor_reference', 120)->nullable();  // vendor bill / challan number
            $table->string('description', 500)->nullable();
            $table->date('bill_date');
            $table->date('paid_on')->nullable();
            $table->string('currency', 5)->default('INR');
            $table->decimal('amount', 15, 2);
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->decimal('amount_bcy', 15, 2);                // INR
            $table->boolean('recoverable')->default(true);        // to be billed to the client
            $table->foreignId('billed_invoice_id')->nullable()->constrained('patent_invoices_in')->nullOnDelete();
            $table->string('source', 20)->default('portal');      // portal | zoho_bill | zoho_expense
            $table->string('zoho_id', 80)->nullable()->unique();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['project_id', 'kind']);
            $table->index(['bill_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_costs');
    }
};
