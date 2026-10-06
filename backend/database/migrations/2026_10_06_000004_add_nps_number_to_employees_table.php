<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employees', 'nps_number')) {
            Schema::table('employees', function (Blueprint $table) {
                // text, not string — matches the EncryptedSafe-cast columns
                // (uan_pf_number/esi_number stay string since they were never
                // encrypted; nps_number is encrypted from day one, so it needs
                // the wider column ciphertext requires).
                $table->text('nps_number')->nullable()->after('uan_pf_number');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employees', 'nps_number')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('nps_number');
            });
        }
    }
};
