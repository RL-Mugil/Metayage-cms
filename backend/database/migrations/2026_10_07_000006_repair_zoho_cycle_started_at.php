<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Early installations applied the storage migration before cycle tracking
        // was added. Repair that schema without resetting saved page checkpoints.
        if (! Schema::hasColumn('zoho_sync_progress', 'started_at')) {
            Schema::table('zoho_sync_progress', fn (Blueprint $table) => $table->timestamp('started_at')->nullable());
        }
        DB::table('zoho_sync_progress')->where('resource', '__cycle')->whereNull('started_at')->update(['started_at' => now()]);
    }

    public function down(): void
    {
        // The column belongs to the original storage schema; keep it on rollback.
    }
};
