<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_components', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            // 'flat' = fixed ₹ amount; 'percentage' = % of percentage_of; 'remainder'
            // = whatever's left of payable gross after all other active components
            // (exactly one active row may be 'remainder' — enforced in SalaryComponentValidator).
            $table->string('calc_type');
            $table->decimal('value', 12, 2)->nullable();
            // A component code this one's percentage is computed against, or the
            // sentinel '_GROSS_' for the LOP-adjusted payable gross itself. Only
            // meaningful when calc_type = 'percentage'.
            $table->string('percentage_of')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_components');
    }
};
