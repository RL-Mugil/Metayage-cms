<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the pcm_user_id / scm_user_id / pr_user_id columns that were added
 * by a June 5 migration before the canonical pcm_id / scm_id / pr_id columns
 * were introduced in the June 12 migration. These three columns are unused by
 * any controller, model relationship, or query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracker_rows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pcm_user_id');
            $table->dropConstrainedForeignId('scm_user_id');
            $table->dropConstrainedForeignId('pr_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('tracker_rows', function (Blueprint $table) {
            $table->foreignId('pcm_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('scm_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('pr_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }
};
