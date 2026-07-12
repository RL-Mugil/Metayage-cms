<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patent_invoices_in', function (Blueprint $table) {
            $table->id();

            // Record type
            $table->string('type', 10)->default('invoice'); // 'invoice' | 'quote'
            $table->string('status', 30)->default('Draft'); // Draft | Sent | Accepted | Rejected | Cancelled

            // References
            $table->foreignId('project_id')->constrained('projects');
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('created_by_id')->nullable()->constrained('users');

            // UIN fields
            $table->string('docket_number', 70)->index();       // from project.docket_number
            $table->string('invoice_uin', 80)->nullable();      // docket or docket/N (computed on create)

            // Dates
            $table->date('invoice_date')->nullable();            // auto-today, editable
            $table->date('tax_invoice_date')->nullable();        // invoice only
            $table->string('tax_serial_number', 60)->nullable(); // invoice only

            // Auto-computed from UIN
            $table->string('client_code_prefix', 10)->nullable();  // chars 1-4 of UIN
            $table->string('invention_number', 10)->nullable();    // chars 5-7 of UIN
            $table->string('patent_office_code', 10)->nullable();  // chars 8-9 of UIN / project field

            // Auto-fetched from project (editable)
            $table->string('first_inventor_name', 255)->nullable();
            $table->text('invention_title')->nullable();
            $table->string('service_code', 20)->nullable();

            // Auto-fetched from client (editable)
            $table->string('client_name', 255)->nullable();
            $table->string('client_reference', 50)->nullable(); // client.referred_by_code
            $table->string('state_of_supply', 100)->nullable(); // client.state

            // Manual entry
            $table->string('entity_status', 150)->nullable();
            $table->string('patent_office_application_number', 150)->nullable();
            $table->text('additional_information')->nullable();
            $table->text('patent_office_acknowledgement')->nullable();
            $table->text('remarks')->nullable();
            $table->string('uin_old', 80)->nullable();
            $table->string('uin_old_2', 80)->nullable();

            // Financials (INR) — manual entry
            $table->decimal('patent_office_fees', 15, 2)->default(0);
            $table->decimal('service_fees', 15, 2)->default(0);
            $table->decimal('other_expenses', 15, 2)->default(0);

            // GST — auto-calculated from service_fees + state_of_supply
            $table->decimal('igst_amount', 15, 2)->default(0);
            $table->decimal('cgst_amount', 15, 2)->default(0);
            $table->decimal('sgst_amount', 15, 2)->default(0);

            // Totals — auto-calculated
            $table->decimal('invoice_amount', 15, 2)->default(0); // pof + svc + gst + other
            $table->decimal('attorney_fees', 15, 2)->default(0);  // internal — never shown to client
            $table->decimal('consultant_fees', 15, 2)->default(0); // internal
            $table->decimal('referral_fees', 15, 2)->default(0);   // internal
            $table->decimal('net_revenue', 15, 2)->default(0);    // invoice_amount - atty - consult - referral

            $table->string('currency', 5)->default('INR');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patent_invoices_in');
    }
};
