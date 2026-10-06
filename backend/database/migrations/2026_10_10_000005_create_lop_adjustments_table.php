<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lop_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // Set once an adjustment is actually folded into a new run's payslip.
            $table->foreignId('payslip_id')->nullable()->constrained('payslips')->nullOnDelete();
            $table->string('adjustment_type'); // Reversal | Correction
            $table->decimal('days', 5, 2);
            $table->date('period'); // the already-finalized month being corrected
            $table->text('reason')->nullable();
            $table->foreignId('applied_in_run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('Pending'); // Pending | Applied
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lop_adjustments');
    }
};
