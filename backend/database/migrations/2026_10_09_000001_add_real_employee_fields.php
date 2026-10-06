<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'father_name')) {
                $table->string('father_name')->nullable()->after('full_name');
            }
            if (! Schema::hasColumn('employees', 'differently_abled_type')) {
                $table->string('differently_abled_type')->nullable()->after('blood_group');
            }
            // Per-employee Professional Tax opt-out — PT is otherwise a single
            // firm-wide flat amount (system_settings.payroll_pt_monthly) applied
            // to everyone; this lets HR/Finance exempt one person (e.g. a
            // location where PT doesn't apply) without disabling it firm-wide.
            if (! Schema::hasColumn('employees', 'professional_tax_exempt')) {
                $table->boolean('professional_tax_exempt')->default(false)->after('salary');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['father_name', 'differently_abled_type', 'professional_tax_exempt']);
        });
    }
};
