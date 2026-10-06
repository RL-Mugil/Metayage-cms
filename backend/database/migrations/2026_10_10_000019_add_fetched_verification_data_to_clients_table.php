<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Government-returned entity name/address, for review and form pre-fill — see EntityLockerVerificationService. */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'gstin_verified_name')) {
                $table->string('gstin_verified_name')->nullable()->after('gstin_verified_at');
            }
            if (! Schema::hasColumn('clients', 'gstin_verified_address')) {
                $table->text('gstin_verified_address')->nullable()->after('gstin_verified_name');
            }
            if (! Schema::hasColumn('clients', 'cin_verified_name')) {
                $table->string('cin_verified_name')->nullable()->after('cin_verified_at');
            }
            if (! Schema::hasColumn('clients', 'cin_verified_address')) {
                $table->text('cin_verified_address')->nullable()->after('cin_verified_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['gstin_verified_name', 'gstin_verified_address', 'cin_verified_name', 'cin_verified_address']);
        });
    }
};
