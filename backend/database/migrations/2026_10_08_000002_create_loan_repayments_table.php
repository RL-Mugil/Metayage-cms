<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('loan_repayments')) {
            return;
        }

        Schema::create('loan_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            // Un-links (rather than deletes) if the payslip is regenerated — the same
            // nullOnDelete pattern already used for expense_claims.payslip_id.
            $table->foreignId('payslip_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('installment_amount', 12, 2);
            $table->date('period');
            $table->string('status')->default('Scheduled'); // Scheduled, Deducted
            $table->timestamps();

            $table->index(['loan_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_repayments');
    }
};
