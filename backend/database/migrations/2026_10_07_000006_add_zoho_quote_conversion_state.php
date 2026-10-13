<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('zoho_quote_exports', function (Blueprint $table) {
            $table->string('invoice_id', 80)->nullable()->after('estimate_id');
            $table->string('conversion_state', 20)->default('not_requested')->after('state');
            $table->timestamp('converted_at')->nullable()->after('conversion_state');
            $table->unique(['scope', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::table('zoho_quote_exports', function (Blueprint $table) {
            $table->dropUnique(['scope', 'invoice_id']);
            $table->dropColumn(['invoice_id', 'conversion_state', 'converted_at']);
        });
    }
};
