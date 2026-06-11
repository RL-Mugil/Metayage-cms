<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->date('period')->unique(); // first day of the payroll month
            $table->string('status')->default('Draft'); // Draft, Finalized, Paid
            $table->unsignedInteger('employee_count')->default(0);
            $table->decimal('gross_total', 15, 2)->default(0);
            $table->decimal('deductions_total', 15, 2)->default(0);
            $table->decimal('net_total', 15, 2)->default(0);
            $table->foreignId('processed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            // Snapshots — payslips must stay correct even if the employee record changes later
            $table->string('employee_name');
            $table->string('employee_code')->nullable();
            $table->string('designation')->nullable();
            $table->decimal('gross_salary', 12, 2);     // monthly gross at time of run
            $table->decimal('lop_days', 4, 1)->default(0);
            $table->decimal('lop_deduction', 12, 2)->default(0);
            $table->decimal('basic', 12, 2)->default(0);
            $table->decimal('hra', 12, 2)->default(0);
            $table->decimal('special_allowance', 12, 2)->default(0);
            $table->decimal('pf_employee', 12, 2)->default(0);
            $table->decimal('esi_employee', 12, 2)->default(0);
            $table->decimal('professional_tax', 12, 2)->default(0);
            $table->decimal('tds', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
    }
};
