<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membership table for participant-scoped discussion threads — primarily
 * direct messages (kind = dm), where access is defined by who is in the room
 * rather than by role/client scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('discussion_participants')) {
            Schema::create('discussion_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('thread_id')->constrained('discussion_threads')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['thread_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('discussion_participants');
    }
};
