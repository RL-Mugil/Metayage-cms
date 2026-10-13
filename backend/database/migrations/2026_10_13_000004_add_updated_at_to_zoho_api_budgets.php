<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('zoho_api_budgets', 'updated_at')) {
            return;
        }

        Schema::table('zoho_api_budgets', function (Blueprint $table) {
            $table->timestamp('updated_at')->nullable();
        });

        // Existing rows: the best available "last touched" time is the last Zoho call.
        DB::table('zoho_api_budgets')->update(['updated_at' => DB::raw('COALESCE(last_call_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('zoho_api_budgets', 'updated_at')) {
            Schema::table('zoho_api_budgets', function (Blueprint $table) {
                $table->dropColumn('updated_at');
            });
        }
    }
};
