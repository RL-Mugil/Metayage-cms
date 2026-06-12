<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('performance_feedback360')->delete();
        DB::table('performance_goals')->delete();
        DB::table('performance_reviews')->delete();
        DB::table('job_candidates')->delete();
        DB::table('job_postings')->delete();
        DB::table('offboarding_cases')->delete();
    }

    public function down(): void
    {
        // Data removed intentionally — no rollback
    }
};
