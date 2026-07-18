<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrades the discussion substrate into a real-time, Google-Chat-style case
 * chat: soft-deletable + editable messages, @mentions, and per-user read
 * receipts. discussion_threads already carries project_id and client_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discussion_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('discussion_messages', 'edited_at')) {
                $table->timestamp('edited_at')->nullable()->after('content');
            }
            if (! Schema::hasColumn('discussion_messages', 'mentions')) {
                // Array of user ids referenced via @mention.
                $table->json('mentions')->nullable()->after('attachments');
            }
            if (! Schema::hasColumn('discussion_messages', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }
        });

        // Flag a thread as the canonical per-case chat room so we never create
        // two chat threads for the same project.
        Schema::table('discussion_threads', function (Blueprint $table) {
            if (! Schema::hasColumn('discussion_threads', 'kind')) {
                $table->string('kind')->default('thread')->after('tag'); // thread | case_chat
            }
        });

        if (! Schema::hasTable('discussion_message_reads')) {
            Schema::create('discussion_message_reads', function (Blueprint $table) {
                $table->id();
                $table->foreignId('thread_id')->constrained('discussion_threads')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                // Highest message id this user has read in the thread.
                $table->unsignedBigInteger('last_read_message_id')->default(0);
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->unique(['thread_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('discussion_message_reads');

        Schema::table('discussion_messages', function (Blueprint $table) {
            foreach (['edited_at', 'mentions', 'deleted_at'] as $col) {
                if (Schema::hasColumn('discussion_messages', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('discussion_threads', function (Blueprint $table) {
            if (Schema::hasColumn('discussion_threads', 'kind')) {
                $table->dropColumn('kind');
            }
        });
    }
};
