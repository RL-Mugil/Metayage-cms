<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Government-returned demographic data captured alongside verification,
     * so a mismatch (vs. our stored full_name/date_of_birth/current_address)
     * can be reviewed and the form pre-filled from the verified source rather
     * than silently overwritten. See PanVerificationService/DigiLockerConsentService.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'pan_verified_dob')) {
                $table->date('pan_verified_dob')->nullable()->after('pan_verified_name');
            }
            if (! Schema::hasColumn('employees', 'aadhaar_verified_name')) {
                $table->string('aadhaar_verified_name')->nullable()->after('aadhaar_verified_at');
            }
            if (! Schema::hasColumn('employees', 'aadhaar_verified_dob')) {
                $table->date('aadhaar_verified_dob')->nullable()->after('aadhaar_verified_name');
            }
            if (! Schema::hasColumn('employees', 'aadhaar_verified_address')) {
                $table->text('aadhaar_verified_address')->nullable()->after('aadhaar_verified_dob');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['pan_verified_dob', 'aadhaar_verified_name', 'aadhaar_verified_dob', 'aadhaar_verified_address']);
        });
    }
};
