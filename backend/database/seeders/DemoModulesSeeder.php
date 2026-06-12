<?php

namespace Database\Seeders;

use App\Models\Integration;
use Illuminate\Database\Seeder;

/**
 * Seeds the operational modules with their launch demo data.
 * Idempotent: each table is only seeded when empty.
 */
class DemoModulesSeeder extends Seeder
{
    public function run(): void
    {
        if (Integration::count() === 0) {
            $integrations = [
                ['gcal', 'Google Calendar', 'Sync IP deadlines and meetings', 'Productivity', 'GC', 'bg-blue-500', true, '5 min ago', 'Every 15 min'],
                ['slack', 'Slack', 'Send deadline alerts and notifications', 'Communication', 'SL', 'bg-purple-600', true, 'Real-time', 'Real-time'],
                ['gmail', 'Gmail / SMTP', 'Send emails and client communications', 'Email', 'GM', 'bg-red-500', true, '2 hours ago', 'On demand'],
                ['qb', 'QuickBooks', 'Sync invoices and financial records', 'Accounting', 'QB', 'bg-green-600', false, null, null],
                ['uspto', 'USPTO API', 'Fetch patent filing and examination status', 'IP Office', 'US', 'bg-blue-800', true, '1 hour ago', 'Hourly'],
                ['epo', 'EPO OPS', 'European patent data and family information', 'IP Office', 'EP', 'bg-indigo-600', true, '2 hours ago', 'Every 6 hours'],
                ['docusign', 'DocuSign', 'E-signature for contracts and agreements', 'Legal', 'DS', 'bg-amber-600', false, null, null],
                ['teams', 'Microsoft Teams', 'Meeting scheduling and notifications', 'Communication', 'MT', 'bg-blue-700', false, null, null],
            ];
            foreach ($integrations as [$slug, $name, $desc, $category, $initials, $color, $connected, $lastSync, $syncFreq]) {
                Integration::create([
                    'slug' => $slug, 'name' => $name, 'description' => $desc, 'category' => $category,
                    'initials' => $initials, 'color' => $color, 'connected' => $connected,
                    'last_sync' => $lastSync, 'sync_freq' => $syncFreq,
                ]);
            }
        }
    }
}
