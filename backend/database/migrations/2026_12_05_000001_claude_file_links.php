<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Download links for files Claude produces (App\Services\ClaudeFiles): 30 minutes, token stored hashed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claude_file_links', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->string('file', 10);
            $table->json('params')->nullable();
            $table->timestamp('expires_at')->index();
            $table->unsignedInteger('downloads')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claude_file_links');
    }
};
