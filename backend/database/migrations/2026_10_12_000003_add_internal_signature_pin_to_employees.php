<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('internal_signature_pin_hash')->nullable()->after('photo_path');
        });

        DB::table('employees')->whereNull('internal_signature_pin_hash')->update([
            'internal_signature_pin_hash' => Hash::make('0000'),
        ]);
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('internal_signature_pin_hash');
        });
    }
};
