<?php

namespace Database\Seeders;

use App\Models\ComplianceItem;
use App\Models\FeedbackEntry;
use App\Models\Integration;
use App\Models\Reminder;
use App\Models\User;
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

        if (FeedbackEntry::count() === 0) {
            $entries = [
                ['Acme Corporation', 5, 'Outstanding handling of our patent portfolio. The team caught a critical prior-art issue before filing that saved us months of prosecution.', '2026-06-08', 'Overall'],
                ['Tech Solutions Ltd', 4, 'Very thorough trademark clearance search. Would appreciate slightly faster initial responses, but the quality of work is excellent.', '2026-06-05', 'Service'],
                ['InnovateTech Inc', 5, 'PCT national phase entries were completed well ahead of deadline across all five jurisdictions. Impressive turnaround.', '2026-05-28', 'Turnaround'],
                ['GlobalPatent Group', 3, 'Good legal work, but we had to chase for status updates on the EPO opposition. A monthly summary would help.', '2026-05-22', 'Communication'],
                ['BioMed Research', 5, "The team's understanding of biotech claims is exceptional. Our examiner interviews went smoothly thanks to their preparation.", '2026-05-18', 'Service'],
                ['StartupLabs', 4, 'Great value for a startup budget. The provisional-to-PCT strategy advice was practical and clear.', '2026-05-12', 'Overall'],
                ['Enterprise Corp', 2, 'Renewal reminder came too close to the deadline for comfort. We expect at least 60 days notice for maintenance fees.', '2026-05-06', 'Turnaround'],
                ['FutureMark LLC', 4, 'Responsive team and clear fee estimates. The trademark watch reports are detailed and actionable.', '2026-04-30', 'Communication'],
            ];
            foreach ($entries as [$client, $rating, $comment, $date, $category]) {
                FeedbackEntry::create([
                    'client_name' => $client, 'rating' => $rating, 'comment' => $comment,
                    'entry_date' => $date, 'category' => $category,
                ]);
            }
        }

        $owner = User::where('role', 'super_admin')->first() ?? User::first();
        if ($owner && Reminder::count() === 0) {
            $reminders = [
                ['Pay USPTO maintenance fee — US9876543', '3.5-year window closes June 18 — surcharge applies after', 'Deadline', '2026-06-11', '17:00', 'self', false],
                ['Client call — Acme Corporation', 'Quarterly portfolio review with R&D head', 'Meeting', '2026-06-11', '15:30', 'self', false],
                ['Follow up on EUIPO renewal documents', 'GlobalTech logo trademark — POA still pending from client', 'Renewal', '2026-06-15', null, 'team', false],
                ['Draft FER response — IN202441087', 'First examination report response due to IPO', 'Deadline', '2026-06-17', null, 'self', false],
                ['Send CSAT survey to BioMed Research', 'Post-filing feedback for biotech device application', 'Follow-up', '2026-06-16', null, 'team', true],
                ['EPO renewal fee — EP3456789 (Year 4)', 'Clean energy system patent annuity', 'Renewal', '2026-07-10', null, 'team', false],
                ['PCT national phase entry — PCT/US2024/12345', 'Medical device — confirm target jurisdictions with client', 'Deadline', '2026-08-05', null, 'self', false],
            ];
            foreach ($reminders as [$title, $desc, $category, $due, $time, $scope, $completed]) {
                Reminder::create([
                    'user_id' => $owner->id, 'title' => $title, 'description' => $desc,
                    'category' => $category, 'due_date' => $due, 'due_time' => $time,
                    'scope' => $scope, 'completed' => $completed,
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
