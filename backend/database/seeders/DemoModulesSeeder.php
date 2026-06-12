<?php

namespace Database\Seeders;

use App\Models\ComplianceItem;
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
        if (ComplianceItem::count() === 0) {
            $items = [
                ['US9876543 — Biotech Device Patent', 'Patent', 'USPTO', '2026-06-18', '3.5-year maintenance fee', 'Priya Sharma'],
                ['CTM-2024-00891 — GlobalTech Logo', 'Trademark', 'EUIPO', '2026-06-25', 'Trademark renewal filing', 'Rahul Menon'],
                ['EP3456789 — Clean Energy System', 'Patent', 'EPO', '2026-07-10', 'Annual renewal fee (Year 4)', 'Kavya Nair'],
                ['IN202441087 — AI Algorithm Patent', 'Patent', 'IPO India', '2026-07-22', 'Examination request deadline', 'Priya Sharma'],
                ['PCT/US2024/12345 — Medical Device', 'Patent', 'WIPO', '2026-08-05', 'National phase entry deadline', 'Arjun Patel'],
                ['TM-ACME-BRAND — StellarBrands', 'Trademark', 'USPTO', '2026-08-30', 'Section 8 & 15 filing', 'Rahul Menon'],
                ['US8765432 — Software Patent', 'Patent', 'USPTO', '2026-09-15', '7.5-year maintenance fee', 'Vikram Singh'],
                ['NovaMed Pharma — Class 5 TM', 'Trademark', 'IPO India', '2026-10-01', 'Trademark renewal (10 years)', 'Kavya Nair'],
                ['EP2345678 — Semiconductor Device', 'Patent', 'EPO', '2026-10-20', 'Annual renewal fee (Year 6)', 'Priya Sharma'],
                ['US7654321 — Optical System', 'Patent', 'USPTO', '2026-11-12', '11.5-year maintenance fee', 'Arjun Patel'],
                ['EUIPO-TM-5678 — FutureTech Mark', 'Trademark', 'EUIPO', '2026-12-01', 'Trademark renewal (10 years)', 'Rahul Menon'],
                ['WO2024/09876 — IoT Platform', 'Patent', 'WIPO', '2027-01-15', 'PCT Chapter II demand', 'Vikram Singh'],
                ['US6543210 — Network Protocol', 'Patent', 'USPTO', '2027-02-28', '11.5-year maintenance fee', 'Priya Sharma'],
                ['IN202312456 — Agri-Tech Patent', 'Patent', 'IPO India', '2027-03-10', 'Annual renewal fee (Year 3)', 'Kavya Nair'],
                ['CTM-2020-00234 — StrataTech Logo', 'Trademark', 'EUIPO', '2027-04-22', 'Trademark renewal (10 years)', 'Arjun Patel'],
            ];
            foreach ($items as [$matter, $type, $jurisdiction, $deadline, $action, $assignee]) {
                ComplianceItem::create([
                    'matter' => $matter, 'type' => $type, 'jurisdiction' => $jurisdiction,
                    'deadline' => $deadline, 'action_required' => $action, 'assignee' => $assignee,
                    'status' => 'Open',
                ]);
            }
        }

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
