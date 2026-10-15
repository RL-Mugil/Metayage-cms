<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This migration pre-dates the table's create migration (2026_10_07_000001), which now creates these
        // columns itself. Skip on a fresh database; on an existing one add whatever is still missing.
        if (! Schema::hasTable('zoho_api_budgets')) {
            return;
        }
        Schema::table('zoho_api_budgets', function (Blueprint $table) {
            if (! Schema::hasColumn('zoho_api_budgets', 'emergency_calls')) {
                $table->unsignedInteger('emergency_calls')->default(0);
            }
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
        if (Schema::hasTable('zoho_api_budgets') && Schema::hasColumn('zoho_api_budgets', 'emergency_calls')) {
            Schema::table('zoho_api_budgets', fn (Blueprint $table) => $table->dropColumn('emergency_calls'));
        }
    }
};
