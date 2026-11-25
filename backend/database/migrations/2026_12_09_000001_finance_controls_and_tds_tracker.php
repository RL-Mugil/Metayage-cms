<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance controls and the TDS tracker:
 * - finance_period_locks: a month closed for portal edits (Zoho imports still apply and are flagged);
 * - finance_lock_flags: changes that reached a locked month (from imports), for Accounts to review;
 * - quote_send_approvals: Accounts' approval of a quote, tied to the exact draft sent to Zoho;
 * - tds_records: one per TDS line (tax the client withheld), with its Form 16A and 26AS status;
 * - tds_deductors: a client's TAN as it appears in Form 26AS (kept apart from clients);
 * - tds_26as_entries: the lines of uploaded Form 26AS files.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_period_locks', function (Blueprint $table) {
            $table->id();
            $table->string('month', 7)->unique();                  // YYYY-MM
            $table->foreignId('locked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at');
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('finance_lock_flags', function (Blueprint $table) {
            $table->id();
            $table->string('month', 7)->index();
            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id');
            $table->string('reference', 120)->nullable();
            $table->string('what', 255);
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('quote_send_approvals', function (Blueprint $table) {
            $table->id();
            $table->string('quote_kind', 20);                     // standard | in
            $table->unsignedBigInteger('quote_id');
            $table->string('customer_id', 80)->nullable();
            $table->string('version', 64);                        // ZohoQuoteDraft payload hash
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');      // pending | approved | rejected | superseded
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index(['quote_kind', 'quote_id', 'status']);
        });

        Schema::create('tds_deductors', function (Blueprint $table) {
            $table->id();
            $table->string('tan', 10)->unique();
            $table->string('name', 255);
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('matched_by', 20)->default('name');      // name | manual
            $table->timestamps();
        });

        Schema::create('tds_26as_entries', function (Blueprint $table) {
            $table->id();
            $table->string('tan', 10)->index();
            $table->string('deductor_name', 255)->nullable();
            $table->string('fy', 9)->index();                      // 2026-27
            $table->string('quarter', 2);                          // Q1..Q4
            $table->string('section', 12)->nullable();
            $table->date('transaction_date');
            $table->string('booking_status', 2)->nullable();       // F (final) | U | P | O ...
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('tds', 15, 2);
            $table->string('fingerprint', 64)->unique();
            $table->string('upload_batch', 40)->nullable();
            $table->timestamps();
        });

        Schema::create('tds_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('register_payment_id')->unique()->constrained('register_payments')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('patent_invoice_in_id')->nullable()->constrained('patent_invoices_in')->nullOnDelete();
            $table->string('payment_number', 80)->nullable();
            $table->date('deducted_on');
            $table->string('fy', 9)->index();
            $table->string('quarter', 2);
            $table->decimal('amount', 15, 2);                      // INR
            $table->string('status', 20)->default('pending');      // pending | in_26as | mismatch
            $table->foreignId('tds_26as_entry_id')->nullable()->constrained('tds_26as_entries')->nullOnDelete();
            $table->date('form16a_received_on')->nullable();
            $table->string('form16a_certificate_no', 60)->nullable();
            $table->foreignId('form16a_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index(['client_id', 'fy', 'quarter']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tds_records');
        Schema::dropIfExists('tds_26as_entries');
        Schema::dropIfExists('tds_deductors');
        Schema::dropIfExists('quote_send_approvals');
        Schema::dropIfExists('finance_lock_flags');
        Schema::dropIfExists('finance_period_locks');
    }
};
