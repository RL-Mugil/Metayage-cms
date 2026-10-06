<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Client business-document verification (GST registration, incorporation) via EntityLocker. */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'gstin_verification_status')) {
                $table->string('gstin_verification_status')->default('Unverified')->after('gstin'); // Unverified | Verified | Mismatch | Failed
            }
            if (! Schema::hasColumn('clients', 'gstin_verified_at')) {
                $table->timestamp('gstin_verified_at')->nullable()->after('gstin_verification_status');
            }
            if (! Schema::hasColumn('clients', 'cin_verification_status')) {
                $table->string('cin_verification_status')->default('Unverified')->after('cin_number');
            }
            if (! Schema::hasColumn('clients', 'cin_verified_at')) {
                $table->timestamp('cin_verified_at')->nullable()->after('cin_verification_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['gstin_verification_status', 'gstin_verified_at', 'cin_verification_status', 'cin_verified_at']);
        });
    }
};
