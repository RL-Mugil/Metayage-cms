<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks when ImportTrackerAndProjectsCommand last wrote `status` for a
 * project, so a later import run can tell "nobody has touched status since
 * we last synced it" (safe to overwrite) apart from "a manual edit happened
 * in between" (updated_at will be newer than this — skip, don't clobber it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('tracker_status_synced_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('tracker_status_synced_at');
        });
    }
};
