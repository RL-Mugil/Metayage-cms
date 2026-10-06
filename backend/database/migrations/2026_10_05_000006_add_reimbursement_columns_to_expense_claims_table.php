<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('expense_claims', 'reimbursement_type_id')) {
            Schema::table('expense_claims', function (Blueprint $table) {
                $table->foreignId('reimbursement_type_id')->nullable()->after('employee_id')
                    ->constrained('reimbursement_types')->nullOnDelete();
            });
        }

        // Set once a claim has been swept into a payroll run — null again if that
        // (still-Draft) run is deleted, so the claim becomes reclaimable.
        if (! Schema::hasColumn('expense_claims', 'payslip_id')) {
            Schema::table('expense_claims', function (Blueprint $table) {
                $table->foreignId('payslip_id')->nullable()->after('approved_by_id')
                    ->constrained('payslips')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('expense_claims', 'payslip_id')) {
            Schema::table('expense_claims', function (Blueprint $table) {
                $table->dropConstrainedForeignId('payslip_id');
            });
        }
        if (Schema::hasColumn('expense_claims', 'reimbursement_type_id')) {
            Schema::table('expense_claims', function (Blueprint $table) {
                $table->dropConstrainedForeignId('reimbursement_type_id');
            });
        }
    }
};
