<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('zoho_api_budgets', function (Blueprint $table): void {
            $table->unsignedInteger('poll_calls')->default(0)->after('automatic_calls');
        });
    }

    public function down(): void
    {
        Schema::table('zoho_api_budgets', function (Blueprint $table): void {
            $table->dropColumn('poll_calls');
        });
    }
};
