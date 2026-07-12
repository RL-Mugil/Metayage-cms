<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->decimal('subtotal', 15, 2)->default(0.00)->after('buffer_percentage');
            $table->decimal('tax_amount', 15, 2)->default(0.00)->after('subtotal');
            $table->json('tax_details')->nullable()->after('tax_amount');
        });

        // Backfill: treat existing total_amount as subtotal (no GST was stored before)
        DB::statement('UPDATE quotations SET subtotal = total_amount WHERE subtotal = 0');
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['subtotal', 'tax_amount', 'tax_details']);
        });
    }
};
