<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            // Draft -> PendingApproval -> Finalized -> Paid, or PendingApproval ->
            // Rejected -> (recall) -> Draft. See PayrollController::submitForApproval/
            // approve/reject/recall.
            if (! Schema::hasColumn('payroll_runs', 'submitted_by_id')) {
                $table->foreignId('submitted_by_id')->nullable()->after('processed_by_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('payroll_runs', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('submitted_by_id');
            }
            if (! Schema::hasColumn('payroll_runs', 'approved_by_id')) {
                $table->foreignId('approved_by_id')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('payroll_runs', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by_id');
            }
            if (! Schema::hasColumn('payroll_runs', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('approved_at');
            }
            // Regular (default) | OffCycle for now; OneTimePayout | Resettlement
            // are a deferred follow-up — see the plan's Phase 1/A4 scope note.
            if (! Schema::hasColumn('payroll_runs', 'run_type')) {
                $table->string('run_type')->default('Regular')->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by_id');
            $table->dropConstrainedForeignId('approved_by_id');
            $table->dropColumn(['submitted_at', 'approved_at', 'rejection_reason', 'run_type']);
        });
    }
};
