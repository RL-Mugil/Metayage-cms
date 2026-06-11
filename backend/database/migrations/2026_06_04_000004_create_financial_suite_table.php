<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quote_code')->unique(); // QUO-YYYY-XXXXX
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->date('valid_until');
            $table->string('fee_structure')->default('Fixed Fee');
            $table->decimal('estimated_hours', 8, 2)->default(0.00);
            $table->json('hourly_rates')->nullable(); // Rates per role (Partner, Associate, Paralegal)
            $table->decimal('estimated_disbursements', 15, 2)->default(0.00);
            $table->decimal('buffer_percentage', 5, 2)->default(0.00);
            $table->decimal('total_amount', 15, 2)->default(0.00);
            $table->string('currency')->default('USD');
            $table->string('status')->default('Draft'); // Draft, Internal Pending, Sent, Accepted, Expired, Cancelled
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_code')->unique(); // INV-YYYY-XXXXX or PRO-YYYY-XXXXX (if proforma)
            $table->string('invoice_type')->default('Standard'); // Standard, Proforma, Recurring, Interim, Final
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('purchase_order')->nullable();
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('currency')->default('USD');
            $table->decimal('subtotal', 15, 2)->default(0.00);
            $table->decimal('tax_amount', 15, 2)->default(0.00);
            $table->decimal('discount_amount', 15, 2)->default(0.00);
            $table->decimal('total_amount', 15, 2)->default(0.00);
            $table->decimal('balance_due', 15, 2)->default(0.00);
            $table->string('payment_terms')->default('Net 30');
            $table->string('status')->default('Draft'); // Draft, Pending Approval, Sent, Viewed, Partially Paid, Paid, Overdue, Cancelled
            $table->json('tax_details')->nullable(); // CGST, SGST, IGST, VAT breakdowns
            $table->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->onDelete('cascade');
            $table->string('description');
            $table->string('hsn_sac_code')->nullable();
            $table->decimal('quantity', 8, 2)->default(1.00);
            $table->decimal('unit_rate', 15, 2)->default(0.00);
            $table->decimal('amount', 15, 2)->default(0.00);
            $table->decimal('tax_rate', 5, 2)->default(0.00); // e.g. 18.00 for 18%
            $table->timestamps();
        });

        Schema::create('client_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->date('transaction_date');
            $table->string('document_type'); // Invoice, Payment, Credit Note, Debit Note, Adjustment
            $table->string('document_reference');
            $table->decimal('debit', 15, 2)->default(0.00); // charged
            $table->decimal('credit', 15, 2)->default(0.00); // received/applied
            $table->decimal('balance', 15, 2)->default(0.00); // running balance
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('receipt_code')->unique(); // REC-YYYY-XXXXX
            $table->date('payment_date');
            $table->decimal('amount', 15, 2);
            $table->string('payment_method'); // Bank Transfer, Stripe, PayPal, Razorpay, Cash
            $table->string('transaction_reference')->nullable();
            $table->string('status')->default('Completed'); // Pending, Completed, Failed, Refunded
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('client_ledger');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('quotations');
    }
};
