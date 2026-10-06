<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            // Selective per-employee payment within a Finalized run — see
            // PayrollController::payslipsBulkPay.
            if (! Schema::hasColumn('payslips', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('net_pay');
            }
            // Salary Withhold: only net_pay disbursement is held back, statutory
            // deductions already computed normally — see PayrollController::
            // withholdPayslip/revertWithhold/releaseWithheld and GeneratePayrollJob.
            if (! Schema::hasColumn('payslips', 'withheld')) {
                $table->boolean('withheld')->default(false)->after('paid_at');
            }
            if (! Schema::hasColumn('payslips', 'withhold_reason')) {
                $table->text('withhold_reason')->nullable()->after('withheld');
            }
            if (! Schema::hasColumn('payslips', 'released_in_run_id')) {
                $table->foreignId('released_in_run_id')->nullable()->after('withhold_reason')->constrained('payroll_runs')->nullOnDelete();
            }
            if (! Schema::hasColumn('payslips', 'withheld_release_amount')) {
                $table->decimal('withheld_release_amount', 12, 2)->default(0)->after('released_in_run_id');
            }
            // Prior-month LOP Adjust (Reversal/Correction) folded additively into
            // the run that applies it — see lop_adjustments table + GeneratePayrollJob.
            if (! Schema::hasColumn('payslips', 'lop_adjustment_amount')) {
                $table->decimal('lop_adjustment_amount', 12, 2)->default(0)->after('withheld_release_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('released_in_run_id');
            $table->dropColumn(['paid_at', 'withheld', 'withhold_reason', 'withheld_release_amount', 'lop_adjustment_amount']);
        });
    }
};
