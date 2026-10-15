<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Same columns as 2026_10_06_000002; kept idempotent so databases that ran either one stay valid, and
        // skipped on a fresh database where the create migration (2026_10_07_000001) already provides them.
        if (! Schema::hasTable('zoho_api_budgets')) {
            return;
        }
        Schema::table('zoho_api_budgets', function (Blueprint $table) {
            if (! Schema::hasColumn('zoho_api_budgets', 'addon_calls')) {
                $table->unsignedInteger('addon_calls')->default(0);
            }
            if (! Schema::hasColumn('zoho_api_budgets', 'addon_limit')) {
                $table->unsignedInteger('addon_limit')->default(0);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('zoho_api_budgets')) {
            Schema::table('zoho_api_budgets', function (Blueprint $table) {
                foreach (['addon_calls', 'addon_limit'] as $column) {
                    if (Schema::hasColumn('zoho_api_budgets', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
