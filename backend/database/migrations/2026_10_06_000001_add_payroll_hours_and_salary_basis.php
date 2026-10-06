<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->unsignedInteger('hours_logged_minutes')->nullable()->after('lop_adjustment_amount');
            $table->unsignedInteger('annual_hours_shortage_minutes')->default(0)->after('hours_logged_minutes');
            $table->decimal('annual_hours_adjustment', 12, 2)->default(0)->after('annual_hours_shortage_minutes');
        });

        Schema::create('payroll_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('period');
            $table->unsignedInteger('minutes');
            $table->foreignId('entered_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_hours');
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['hours_logged_minutes', 'annual_hours_shortage_minutes', 'annual_hours_adjustment']);
        });
    }
};
