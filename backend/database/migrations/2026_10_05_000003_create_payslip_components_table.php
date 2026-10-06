<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshots a payslip's computed earning breakdown at generation time,
     * decoupled from the live/editable salary_components table — editing the
     * template later never rewrites already-generated payslip history.
     */
    public function up(): void
    {
        Schema::create('payslip_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained('payslips')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('calc_type');
            $table->unsignedInteger('sequence')->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['payslip_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_components');
    }
};
