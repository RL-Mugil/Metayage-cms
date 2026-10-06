<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // PAN Verification (API Setu, Income Tax Dept) — see PanVerificationService.
            if (! Schema::hasColumn('employees', 'pan_verification_status')) {
                $table->string('pan_verification_status')->default('Unverified')->after('pan_tax_id'); // Unverified | Verified | NameMismatch | Failed
            }
            if (! Schema::hasColumn('employees', 'pan_verified_at')) {
                $table->timestamp('pan_verified_at')->nullable()->after('pan_verification_status');
            }
            // Snapshot of the government-returned registered name, for displaying
            // a mismatch reason — never a substitute for aadhaar_ssn_encrypted/pan_tax_id.
            if (! Schema::hasColumn('employees', 'pan_verified_name')) {
                $table->string('pan_verified_name')->nullable()->after('pan_verified_at');
            }

            // Aadhaar verification (DigiLocker consent flow) — see DigiLockerConsentService.
            // Deliberately stores only a status + timestamp, never the fetched Aadhaar
            // document or XML — avoids the UIDAI Aadhaar Data Vault storage obligation.
            if (! Schema::hasColumn('employees', 'aadhaar_verification_status')) {
                $table->string('aadhaar_verification_status')->default('Unverified')->after('aadhaar_ssn_encrypted'); // Unverified | Verified | Failed
            }
            if (! Schema::hasColumn('employees', 'aadhaar_verified_at')) {
                $table->timestamp('aadhaar_verified_at')->nullable()->after('aadhaar_verification_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['pan_verification_status', 'pan_verified_at', 'pan_verified_name', 'aadhaar_verification_status', 'aadhaar_verified_at']);
        });
    }
};
