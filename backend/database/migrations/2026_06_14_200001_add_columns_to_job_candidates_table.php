<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_candidates', function (Blueprint $table) {
            if (! Schema::hasColumn('job_candidates', 'job_posting_id')) {
                $table->foreignId('job_posting_id')->nullable()->constrained('job_postings')->nullOnDelete()->after('id');
            }
            if (! Schema::hasColumn('job_candidates', 'email')) {
                $table->string('email')->nullable()->after('name');
            }
            if (! Schema::hasColumn('job_candidates', 'phone')) {
                $table->string('phone', 50)->nullable()->after('email');
            }
            if (! Schema::hasColumn('job_candidates', 'resume_url')) {
                $table->string('resume_url', 1000)->nullable()->after('phone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('job_candidates', function (Blueprint $table) {
            $table->dropForeign(['job_posting_id']);
            $table->dropColumn(['job_posting_id', 'email', 'phone', 'resume_url']);
        });
    }
};
