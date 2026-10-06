<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('salary_revisions', 'previous_salary') && ! Schema::hasColumn('salary_revisions', 'previous_base_pay')) {
            Schema::table('salary_revisions', function (Blueprint $table) {
                $table->renameColumn('previous_salary', 'previous_base_pay');
            });
        }

        if (Schema::hasColumn('salary_revisions', 'new_salary') && ! Schema::hasColumn('salary_revisions', 'new_base_pay')) {
            Schema::table('salary_revisions', function (Blueprint $table) {
                $table->renameColumn('new_salary', 'new_base_pay');
            });
        }

        if (! Schema::hasColumn('salary_revisions', 'new_hra')) {
            Schema::table('salary_revisions', function (Blueprint $table) {
                // Nullable — most revisions only change Base Pay; HRA stays as-is unless specified.
                $table->decimal('new_hra', 12, 2)->nullable()->after('new_base_pay');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('salary_revisions', 'new_hra')) {
            Schema::table('salary_revisions', function (Blueprint $table) {
                $table->dropColumn('new_hra');
            });
        }

        if (Schema::hasColumn('salary_revisions', 'new_base_pay') && ! Schema::hasColumn('salary_revisions', 'new_salary')) {
            Schema::table('salary_revisions', function (Blueprint $table) {
                $table->renameColumn('new_base_pay', 'new_salary');
            });
        }

        if (Schema::hasColumn('salary_revisions', 'previous_base_pay') && ! Schema::hasColumn('salary_revisions', 'previous_salary')) {
            Schema::table('salary_revisions', function (Blueprint $table) {
                $table->renameColumn('previous_base_pay', 'previous_salary');
            });
        }
    }
};
