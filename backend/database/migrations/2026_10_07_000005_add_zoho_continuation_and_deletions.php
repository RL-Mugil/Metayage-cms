<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('zoho_api_budgets', fn (Blueprint $t) => $t->unsignedInteger('evening_calls')->default(0));
        Schema::table('zoho_sync_progress', function (Blueprint $t) {
            $t->date('evening_eligible_date')->nullable();
            $t->string('pause_reason', 40)->nullable();
        });
        Schema::table('zoho_invoices', function (Blueprint $t) {
            $t->string('scope', 80)->default('in:');
            $t->softDeletes();
            $t->dropUnique(['zoho_type', 'zoho_id']);
            $t->unique(['scope', 'zoho_type', 'zoho_id']);
        });
        $raw = DB::table('integrations')->where('slug', 'zoho')->value('config');
        $config = is_string($raw) ? json_decode($raw, true) : (array) $raw;
        $scope = ($config['region'] ?? 'in') . ':' . ($config['organization_id'] ?? '');
        DB::table('zoho_invoices')->update(['scope' => $scope]);
        Schema::table('zoho_snapshots', fn (Blueprint $t) => $t->softDeletes());
        Schema::create('zoho_record_states', function (Blueprint $t) {
            $t->id();
            $t->string('scope', 80);
            $t->string('resource', 30);
            $t->string('remote_id', 80);
            $t->timestamp('deleted_at')->nullable();
            $t->string('deletion_source', 30)->nullable();
            $t->unique(['scope', 'resource', 'remote_id']);
        });
        Schema::create('zoho_webhook_events', function (Blueprint $t) {
            $t->id();
            $t->string('scope', 80);
            $t->string('resource', 30);
            $t->string('remote_id', 80);
            $t->string('event_key', 64)->unique();
            $t->timestamp('received_at');
            $t->timestamp('processed_at')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->index(['processed_at', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        // Multiple organizations may now share IDs. Refuse a rollback that would
        // silently discard records or recreate an invalid unique constraint.
        if (DB::table('zoho_invoices')->select('zoho_type', 'zoho_id')->groupBy('zoho_type', 'zoho_id')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('Resolve cross-organization duplicate IDs before rolling back Zoho scope storage.');
        }
        Schema::dropIfExists('zoho_webhook_events');
        Schema::dropIfExists('zoho_record_states');
        Schema::table('zoho_snapshots', fn (Blueprint $t) => $t->dropSoftDeletes());
        Schema::table('zoho_invoices', function (Blueprint $t) {
            $t->dropUnique(['scope', 'zoho_type', 'zoho_id']);
            $t->unique(['zoho_type', 'zoho_id']);
            $t->dropColumn(['scope', 'deleted_at']);
        });
        Schema::table('zoho_sync_progress', fn (Blueprint $t) => $t->dropColumn(['evening_eligible_date', 'pause_reason']));
        Schema::table('zoho_api_budgets', fn (Blueprint $t) => $t->dropColumn('evening_calls'));
    }
};
