<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracker_rows', function (Blueprint $table) {
            $table->foreignId('pcm_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('scm_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('pr_user_id')->nullable()->constrained('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('tracker_rows', function (Blueprint $table) {
            $table->dropForeignKey(['pcm_user_id']);
            $table->dropForeignKey(['scm_user_id']);
            $table->dropForeignKey(['pr_user_id']);
            $table->dropColumn(['pcm_user_id', 'scm_user_id', 'pr_user_id']);
        });
    }
};
