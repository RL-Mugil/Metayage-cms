<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('investment_declarations')) {
            return;
        }

        Schema::create('investment_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('financial_year'); // e.g. 2026 means FY2026-27
            $table->string('regime_choice')->default('new'); // old, new
            $table->decimal('section_80c_amount', 12, 2)->default(0);
            $table->decimal('section_80d_amount', 12, 2)->default(0);
            $table->decimal('hra_rent_paid', 12, 2)->nullable();
            // Flexible bucket for less common sections (80E, 80G, NPS 80CCD(1B), etc.)
            // rather than a column per section — these change in relevance year to year.
            $table->json('other_deductions_json')->nullable();
            $table->string('status')->default('Draft'); // Draft, Submitted, Approved, Rejected
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'financial_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_declarations');
    }
};
