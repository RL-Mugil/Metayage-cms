<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zoho_api_budgets', function (Blueprint $table) {
            $table->unsignedInteger('addon_calls')->default(0)->after('emergency_calls');
            $table->unsignedInteger('addon_limit')->default(0)->after('addon_calls');
        });
    }

    public function down(): void
    {
        Schema::table('zoho_api_budgets', function (Blueprint $table) {
            $table->dropColumn(['addon_calls', 'addon_limit']);
        });
    }
};
