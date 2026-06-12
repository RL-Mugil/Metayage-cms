<?php

namespace Database\Seeders;

use App\Models\ComplianceItem;
use App\Models\FeedbackEntry;
use App\Models\Integration;
use App\Models\JobCandidate;
use App\Models\JobPosting;
use App\Models\OffboardingCase;
use App\Models\PerformanceFeedback360;
use App\Models\PerformanceGoal;
use App\Models\PerformanceReview;
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

        if (PerformanceReview::count() === 0) {
            $reviews = [
                ['Priya Sharma', 'Vikram Singh', 4.5, 'Completed'],
                ['Rahul Menon', 'Vikram Singh', 4.0, 'Completed'],
                ['Kavya Nair', 'Priya Sharma', 0, 'In Progress'],
                ['Arjun Patel', 'Priya Sharma', 0, 'Not Started'],
                ['Sneha Reddy', 'Vikram Singh', 4.2, 'Completed'],
                ['Karthik Iyer', 'Rahul Menon', 0, 'Not Started'],
            ];
            foreach ($reviews as [$employee, $reviewer, $rating, $status]) {
                PerformanceReview::create([
                    'employee' => $employee, 'reviewer' => $reviewer, 'period' => 'Q2 2026',
                    'rating' => $rating, 'status' => $status,
                ]);
            }
        }

        if (PerformanceGoal::count() === 0) {
            $goals = [
                ['File 12 patent applications in H1 2026', 'Priya Sharma', '30 Jun 2026', 75, 'On Track'],
                ['Reduce average drafting turnaround to 10 days', 'Rahul Menon', '15 Jul 2026', 40, 'At Risk'],
                ['Complete EPO qualification course', 'Kavya Nair', '31 Aug 2026', 60, 'On Track'],
                ['Grow trademark portfolio revenue 20%', 'Arjun Patel', '30 Sep 2026', 35, 'At Risk'],
                ['Mentor 2 junior associates through first filings', 'Sneha Reddy', '31 Dec 2026', 50, 'On Track'],
            ];
            foreach ($goals as [$title, $employee, $due, $progress, $status]) {
                PerformanceGoal::create([
                    'title' => $title, 'employee' => $employee, 'due_label' => $due,
                    'progress' => $progress, 'status' => $status,
                ]);
            }
        }

        if (PerformanceFeedback360::count() === 0) {
            $rows = [
                ['Rahul Menon', 'Priya Sharma', '02 Jun 2026', 'Submitted'],
                ['Kavya Nair', 'Priya Sharma', '02 Jun 2026', 'Pending'],
                ['Arjun Patel', 'Rahul Menon', '05 Jun 2026', 'Submitted'],
                ['Sneha Reddy', 'Kavya Nair', '08 Jun 2026', 'Pending'],
            ];
            foreach ($rows as [$from, $to, $sent, $status]) {
                PerformanceFeedback360::create([
                    'from_name' => $from, 'to_name' => $to, 'sent_label' => $sent, 'status' => $status,
                ]);
            }
        }

        if (JobPosting::count() === 0) {
            $jobs = [
                ['Senior Patent Attorney', 'Legal', '2026-05-12', 18, 'Active'],
                ['Trademark Paralegal', 'Legal Ops', '2026-05-20', 31, 'Active'],
                ['IP Docketing Specialist', 'Operations', '2026-04-28', 24, 'Closed'],
                ['Business Development Manager', 'Growth', '2026-06-02', 9, 'Active'],
            ];
            foreach ($jobs as [$title, $dept, $posted, $applicants, $status]) {
                JobPosting::create([
                    'title' => $title, 'dept' => $dept, 'posted_date' => $posted,
                    'applicants' => $applicants, 'status' => $status,
                ]);
            }
        }

        if (JobCandidate::count() === 0) {
            $candidates = [
                ['Ananya Krishnan', 'Senior Patent Attorney', 'Applied', 'Jun 8'],
                ['Rohit Verma', 'Trademark Paralegal', 'Applied', 'Jun 7'],
                ['Meera Pillai', 'BD Manager', 'Applied', 'Jun 6'],
                ['Sanjay Kumar', 'Senior Patent Attorney', 'Screening', 'Jun 4'],
                ['Divya Raghavan', 'Trademark Paralegal', 'Screening', 'Jun 3'],
                ['Aditya Rao', 'Senior Patent Attorney', 'Interview', 'May 30'],
                ['Nisha Thomas', 'BD Manager', 'Interview', 'May 28'],
                ['Farhan Ali', 'Trademark Paralegal', 'Offer', 'May 26'],
                ['Lakshmi Narayanan', 'IP Docketing Specialist', 'Hired', 'May 15'],
            ];
            foreach ($candidates as [$name, $role, $stage, $date]) {
                JobCandidate::create([
                    'name' => $name, 'role' => $role, 'stage' => $stage, 'applied_label' => $date,
                ]);
            }
        }

        if (OffboardingCase::count() === 0) {
            $cases = [
                ['Deepak Chawla', 'Engineering', '27 Jun 2026', 'Resignation', 5, 'Anita Desai', 'In Progress', null],
                ['Sunita Rao', 'Finance', '30 Jun 2026', 'Retirement', 3, 'Anita Desai', 'In Progress', null],
                ['Manoj Gupta', 'Legal Ops', '15 Jul 2026', 'Resignation', 0, 'Ravi Shankar', 'Scheduled', null],
                ['Tarun Mehta', 'Sales', '30 Apr 2026', 'Resignation', 8, 'Anita Desai', 'Completed', '05 May 2026'],
                ['Geeta Joshi', 'Admin', '31 Mar 2026', 'Retirement', 8, 'Ravi Shankar', 'Completed', '02 Apr 2026'],
            ];
            foreach ($cases as [$employee, $dept, $lastDay, $exitType, $done, $hr, $status, $completedLabel]) {
                OffboardingCase::create([
                    'employee' => $employee, 'dept' => $dept, 'last_day' => $lastDay,
                    'exit_type' => $exitType, 'assigned_hr' => $hr, 'status' => $status,
                    'checklist' => array_map(fn ($i) => $i < $done, range(0, 7)),
                    'completed_label' => $completedLabel,
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
