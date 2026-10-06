<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zoho_api_budgets', function (Blueprint $table) {
            $table->timestamp('blocked_until', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('zoho_api_budgets', fn (Blueprint $table) => $table->dropColumn('blocked_until'));
    }
};
