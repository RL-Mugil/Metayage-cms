<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proof_of_investments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_declaration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('financial_year');

            $table->decimal('approved_section_80c_amount', 12, 2)->nullable();
            $table->decimal('approved_section_80d_amount', 12, 2)->nullable();
            $table->decimal('approved_hra_rent_paid', 12, 2)->nullable();
            $table->decimal('approved_home_loan_interest_amount', 12, 2)->nullable();
            $table->decimal('approved_other_income_amount', 12, 2)->nullable();
            $table->json('approved_other_deductions_json')->nullable();
            $table->json('rejection_reasons_json')->nullable(); // keyed by section code, e.g. {"80C": "..."}

            $table->string('status')->default('PendingReview'); // PendingReview | Considered
            $table->timestamp('consider_for_it_at')->nullable();
            $table->foreignId('considered_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->unique(['employee_id', 'financial_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proof_of_investments');
    }
};
