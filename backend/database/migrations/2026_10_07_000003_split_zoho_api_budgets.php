<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('zoho_api_budgets', function (Blueprint $table) {
            $table->unsignedInteger('automatic_calls')->default(0);
            $table->unsignedInteger('manual_read_calls')->default(0);
            $table->unsignedInteger('write_calls')->default(0);
        });
        // Legacy usage cannot be classified reliably. Charge it to automatic reads;
        // retain the total counter so deploying mid-day can never grant extra calls.
        DB::table('zoho_api_budgets')->update(['automatic_calls' => DB::raw('calls')]);
    }

    public function down(): void
    {
        Schema::table('zoho_api_budgets', fn (Blueprint $table) => $table->dropColumn(['automatic_calls', 'manual_read_calls', 'write_calls']));
    }
};
