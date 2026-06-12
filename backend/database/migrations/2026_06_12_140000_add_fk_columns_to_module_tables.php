<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // compliance_items: assignee_id FK (assignee string column stays for display)
        if (! Schema::hasColumn('compliance_items', 'assignee_id')) {
            Schema::table('compliance_items', function (Blueprint $table) {
                $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete()->after('assignee');
            });
        }

        // performance_reviews: employee_id + reviewer_id FKs
        Schema::table('performance_reviews', function (Blueprint $table) {
            if (! Schema::hasColumn('performance_reviews', 'employee_id')) {
                $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete()->after('employee');
            }
            if (! Schema::hasColumn('performance_reviews', 'reviewer_id')) {
                $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete()->after('reviewer');
            }
        });

        // performance_goals: employee_id FK
        if (! Schema::hasColumn('performance_goals', 'employee_id')) {
            Schema::table('performance_goals', function (Blueprint $table) {
                $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete()->after('employee');
            });
        }

        // offboarding_cases: employee_id + assigned_hr_id FKs
        Schema::table('offboarding_cases', function (Blueprint $table) {
            if (! Schema::hasColumn('offboarding_cases', 'employee_id')) {
                $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete()->after('employee');
            }
            if (! Schema::hasColumn('offboarding_cases', 'assigned_hr_id')) {
                $table->foreignId('assigned_hr_id')->nullable()->constrained('users')->nullOnDelete()->after('assigned_hr');
            }
        });

        // job_candidates: job_posting_id FK
        if (! Schema::hasColumn('job_candidates', 'job_posting_id')) {
            Schema::table('job_candidates', function (Blueprint $table) {
                $table->foreignId('job_posting_id')->nullable()->constrained('job_postings')->cascadeOnDelete()->after('id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('job_candidates', 'job_posting_id')) {
            Schema::table('job_candidates', function (Blueprint $table) {
                $table->dropForeign(['job_posting_id']);
                $table->dropColumn('job_posting_id');
            });
        }

        Schema::table('offboarding_cases', function (Blueprint $table) {
            if (Schema::hasColumn('offboarding_cases', 'assigned_hr_id')) {
                $table->dropForeign(['assigned_hr_id']);
                $table->dropColumn('assigned_hr_id');
            }
            if (Schema::hasColumn('offboarding_cases', 'employee_id')) {
                $table->dropForeign(['employee_id']);
                $table->dropColumn('employee_id');
            }
        });

        if (Schema::hasColumn('performance_goals', 'employee_id')) {
            Schema::table('performance_goals', function (Blueprint $table) {
                $table->dropForeign(['employee_id']);
                $table->dropColumn('employee_id');
            });
        }

        Schema::table('performance_reviews', function (Blueprint $table) {
            if (Schema::hasColumn('performance_reviews', 'reviewer_id')) {
                $table->dropForeign(['reviewer_id']);
                $table->dropColumn('reviewer_id');
            }
            if (Schema::hasColumn('performance_reviews', 'employee_id')) {
                $table->dropForeign(['employee_id']);
                $table->dropColumn('employee_id');
            }
        });

        if (Schema::hasColumn('compliance_items', 'assignee_id')) {
            Schema::table('compliance_items', function (Blueprint $table) {
                $table->dropForeign(['assignee_id']);
                $table->dropColumn('assignee_id');
            });
        }
    }
};
