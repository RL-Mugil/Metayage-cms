<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zoho_api_budgets', function (Blueprint $table) {
            $table->string('scope', 80)->primary();
            $table->date('budget_date');
            $table->unsignedInteger('calls')->default(0);
            $table->timestamp('last_call_at', 6)->nullable();
            // Added by 2026_10_06_000002/3, which run before this table exists on a fresh database.
            $table->unsignedInteger('emergency_calls')->default(0);
            $table->unsignedInteger('addon_calls')->default(0);
            $table->unsignedInteger('addon_limit')->default(0);
        });
        Schema::create('zoho_sync_progress', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 80);
            $table->string('resource', 30);
            $table->unsignedInteger('next_page')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unique(['scope', 'resource']);
        });
        Schema::create('zoho_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 80);
            $table->string('resource', 30);
            $table->string('remote_id', 80);
            $table->text('summary');
            $table->text('detail')->nullable();
            $table->string('fingerprint', 64);
            $table->string('detail_fingerprint', 64)->nullable();
            $table->string('pdf_fingerprint', 64)->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamp('detail_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['scope', 'resource', 'remote_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zoho_snapshots');
        Schema::dropIfExists('zoho_sync_progress');
        Schema::dropIfExists('zoho_api_budgets');
    }
};
