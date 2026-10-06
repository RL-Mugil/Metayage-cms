<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tds_liabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payslip_id')->nullable()->constrained('payslips')->nullOnDelete();
            $table->unsignedSmallInteger('financial_year'); // 2026 = FY2026-27
            $table->unsignedTinyInteger('quarter'); // 1 = Apr-Jun ... 4 = Jan-Mar
            $table->date('period'); // copy of payroll_runs.period, for filtering without a join
            $table->decimal('taxable_amount', 12, 2);
            $table->decimal('tds_deducted', 12, 2);
            $table->decimal('tds_paid', 12, 2)->default(0); // denormalized sum of challan_allocations.amount
            $table->date('due_date');
            $table->string('status')->default('Open'); // Open | PartiallyPaid | Paid
            $table->timestamps();

            $table->unique(['employee_id', 'payroll_run_id']);
            $table->index(['financial_year', 'quarter']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tds_liabilities');
    }
};
