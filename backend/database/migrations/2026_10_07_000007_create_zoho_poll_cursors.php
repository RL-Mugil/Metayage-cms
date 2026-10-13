<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('zoho_poll_cursors', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 160);
            $table->string('resource', 40);
            $table->string('last_modified_at', 80)->nullable();
            $table->string('last_remote_id', 80)->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['scope', 'resource']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zoho_poll_cursors');
    }
};
