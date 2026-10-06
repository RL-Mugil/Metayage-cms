<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the three API Setu-marketplace integrations (PAN Verification,
     * DigiLocker, EntityLocker) as rows in the existing `integrations` table —
     * same credential-storage mechanism already used for Zoho Books (see
     * IntegrationController). Starts disconnected with no config; an admin
     * pastes the real Client ID/Secret into Settings -> Integrations once
     * API Setu approves the pending registration.
     */
    public function up(): void
    {
        $now = now();
        $rows = [
            [
                'slug' => 'api_setu', 'name' => 'PAN Verification (API Setu)',
                'description' => 'Income Tax Dept. PAN verification for employee payroll onboarding',
                'category' => 'Compliance', 'initials' => 'PV', 'color' => 'bg-orange-600',
            ],
            [
                'slug' => 'digilocker', 'name' => 'DigiLocker',
                'description' => 'Employee-consented Aadhaar/PAN document verification',
                'category' => 'Compliance', 'initials' => 'DL', 'color' => 'bg-violet-600',
            ],
            [
                'slug' => 'entitylocker', 'name' => 'EntityLocker',
                'description' => 'Client business-document verification (GST, incorporation)',
                'category' => 'Compliance', 'initials' => 'EL', 'color' => 'bg-amber-700',
            ],
        ];

        foreach ($rows as $row) {
            DB::table('integrations')->updateOrInsert(
                ['slug' => $row['slug']],
                [
                    'name' => $row['name'], 'description' => $row['description'], 'category' => $row['category'],
                    'initials' => $row['initials'], 'color' => $row['color'],
                    'connected' => false, 'last_sync' => null, 'sync_freq' => null,
                    'created_at' => $now, 'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('integrations')->whereIn('slug', ['api_setu', 'digilocker', 'entitylocker'])->delete();
    }
};
