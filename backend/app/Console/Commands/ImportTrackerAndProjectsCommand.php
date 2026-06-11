<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\TrackerCircle;
use App\Models\TrackerRow;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class ImportTrackerAndProjectsCommand extends Command
{
    protected $signature = 'import:tracker {file}';
    protected $description = 'Import employees, projects, and tracker rows';

    // ── Employee data from image ──────────────────────────────────────────────
    private const EMPLOYEES = [
        ['name' => 'Keerthana Sivaraju',    'code' => 'A088', 'email' => 'keerthana@myipstrategy.com',     'role' => 'associate'],
        ['name' => 'Prasanth GK',           'code' => 'A51',  'email' => 'prasanth@myipstrategy.com',      'role' => 'manager'],
        ['name' => 'Abinaya Raja',          'code' => 'A114', 'email' => 'abinaya@myipstrategy.com',       'role' => 'associate'],
        ['name' => 'Srija',                 'code' => 'A087', 'email' => 'srija@myipstrategy.com',         'role' => 'associate'],
        ['name' => 'Mohana Keertika',       'code' => 'A100', 'email' => 'keerthika@myipstrategy.com',     'role' => 'associate'],
        ['name' => 'Krishnapriya Sundar',   'code' => 'A089', 'email' => 'krishnapriyaa@myipstrategy.com', 'role' => 'associate'],
        ['name' => 'Muruganantham S',       'code' => 'A010', 'email' => 'muruganantham@myipstrategy.com', 'role' => 'manager'],
        ['name' => 'Mugilvannan',           'code' => 'A116', 'email' => 'mugilvannan@myipstrategy.com',   'role' => 'super_admin'],
        ['name' => 'Vignesh J',             'code' => 'A117', 'email' => 'vignesh@myipstrategy.com',       'role' => 'associate'],
        ['name' => 'Dhanushiya M',          'code' => 'A102', 'email' => 'dhanushiya@myipstrategy.com',    'role' => 'associate'],
        ['name' => 'Priya Vardhini',        'code' => 'A111', 'email' => 'priya@myipstrategy.com',         'role' => 'associate'],
        ['name' => 'Rajalakshmi',           'code' => 'A112', 'email' => 'rajalakshmi@myipstrategy.com',   'role' => 'associate'],
        ['name' => 'Anushiya C',            'code' => 'A113', 'email' => 'anushiya@myipstrategy.com',      'role' => 'associate'],
        ['name' => 'Ankita De',             'code' => 'A092', 'email' => 'ankita@myipstrategy.com',        'role' => 'associate'],
        ['name' => 'Shekhar Mazumdar',      'code' => 'A101', 'email' => 'shekhar@myipstrategy.com',       'role' => 'associate'],
    ];

    private array $userMap = []; // email → User

    public function handle(): int
    {
        $this->seedEmployees();
        $this->importFromCSV($this->argument('file'));
        return 0;
    }

    // ── Step 1: Employees ────────────────────────────────────────────────────
    private function seedEmployees(): void
    {
        $this->info('=== Seeding employees ===');
        foreach (self::EMPLOYEES as $emp) {
            $user = User::updateOrCreate(
                ['email' => $emp['email']],
                [
                    'name'     => $emp['name'],
                    'password' => Hash::make('myips@2024'),
                    'role'     => $emp['role'],
                    'status'   => 'Active',
                ]
            );

            // Sync role in permission tables
            $roleId = \DB::table('roles')->where('name', $emp['role'])->value('id');
            if ($roleId) {
                \DB::table('model_has_roles')->updateOrInsert(
                    ['model_type' => 'App\Models\User', 'model_id' => $user->id],
                    ['role_id' => $roleId, 'model_type' => 'App\Models\User', 'model_id' => $user->id]
                );
            }

            // Employee record (won't fail if exists)
            Employee::updateOrCreate(
                ['employee_code' => $emp['code']],
                [
                    'user_id'           => $user->id,
                    'full_name'         => $emp['name'],
                    'work_email'        => $emp['email'],
                    'employment_status' => 'Active',
                    'employment_type'   => 'Full-time',
                    'date_of_joining'   => now(),
                ]
            );

            $this->userMap[$emp['email']] = $user;
            $this->line("  ✓ {$emp['name']} ({$emp['code']})");
        }
        $this->info('Employees done: ' . count(self::EMPLOYEES));
    }

    // ── Step 2: CSV import ────────────────────────────────────────────────────
    private function importFromCSV(string $filePath): void
    {
        if (!file_exists($filePath)) {
            $this->error("File not found: $filePath");
            return;
        }

        $circleB = TrackerCircle::where('slug', 'b')->first();
        if (!$circleB) {
            $this->error("Circle B not found in DB");
            return;
        }

        // Delete existing Circle B rows before re-import
        TrackerRow::where('circle_id', $circleB->id)->delete();

        $handle = fopen($filePath, 'r');
        fgetcsv($handle); // skip header row

        $projCreated = $projSkipped = $rowCreated = 0;
        $sortOrder = 1;

        while (($row = fgetcsv($handle)) !== false) {
            // Skip blank rows
            if (empty(array_filter($row))) continue;

            $docket      = trim($row[0] ?? '');
            $clientName  = trim(preg_replace('/\s+/', ' ', $row[1] ?? ''));
            $recordType  = trim($row[2] ?? 'Patent');
            $pcm         = trim($row[3] ?? '');
            $scm         = trim($row[4] ?? '');
            $pr          = trim($row[5] ?? '');
            $startDateRaw= trim($row[6] ?? '');
            $status      = trim($row[7] ?? '');
            $dueDateRaw  = trim($row[8] ?? '');
            $paymentStatus = trim($row[9] ?? '');
            $pctRaw      = trim($row[10] ?? '0');
            $uin         = trim($row[11] ?? '');

            if ($clientName === '') continue;

            $pct         = (int) str_replace('%', '', $pctRaw);
            $startDate   = $this->parseDate($startDateRaw);
            $dueDate     = $this->parseDate($dueDateRaw);
            $payStatus   = in_array($paymentStatus, ['Paid', 'Partial', 'Pending']) ? $paymentStatus : null;

            // ── Find or create client ──────────────────────────────────────
            $client = Client::whereRaw('LOWER(company_name) LIKE ?', ['%' . strtolower($clientName) . '%'])->first()
                   ?? Client::whereRaw('LOWER(company_name) LIKE ?', ['%' . strtolower(explode(' ', $clientName)[0]) . '%'])->first();

            if (!$client) {
                $client = Client::create([
                    'client_code'  => 'AUTO-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $clientName), 0, 6)) . rand(100, 999),
                    'company_name' => $clientName,
                    'legal_name'   => $clientName,
                    'status'       => 'Active',
                    'client_type'  => 'organization',
                ]);
            }

            // ── Resolve manager user ───────────────────────────────────────
            $managerUser = $this->resolveUser($pcm);
            $partnerUser = $this->resolveUser('muruganantham@myipstrategy.com', true);

            // ── Create Project ────────────────────────────────────────────
            $projectId = null;
            try {
                $projectCode = $docket ?: ('AUTO-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $clientName), 0, 6)) . rand(1000, 9999));

                $project = Project::updateOrCreate(
                    ['docket_number' => $docket ?: null, 'client_id' => $client->id],
                    [
                        'project_code'        => $projectCode,
                        'project_name'        => $clientName . ($docket ? ' - ' . $docket : ''),
                        'client_id'           => $client->id,
                        'case_type'           => $recordType,
                        'project_type'        => $recordType,
                        'assigned_manager_id' => $managerUser?->id,
                        'assigned_partner_id' => $partnerUser?->id,
                        'patent_engineer_id'  => $this->resolveUser($pr)?->id,
                        'status'              => 'Active',
                        'start_date'          => $startDate,
                        'hard_deadline'       => $dueDate,
                    ]
                );
                $projectId = $project->id;

                // Seed pipeline stages if not present
                if (!ProjectStage::where('project_id', $project->id)->exists()) {
                    $stages = ['Intake', 'Drafting', 'Filing', 'Examination', 'Object received', 'Granted', 'Renewal'];
                    foreach ($stages as $i => $stage) {
                        ProjectStage::create([
                            'project_id'     => $project->id,
                            'stage_name'     => $stage,
                            'status'         => $i === 0 ? 'In Progress' : 'Pending',
                            'sequence_order' => $i,
                            'duration_days'  => 15,
                            'due_date'       => now()->addDays(($i + 1) * 15),
                        ]);
                    }
                }
                $projCreated++;
            } catch (\Exception $e) {
                $this->warn("  Project skipped ({$clientName}): " . $e->getMessage());
                $projSkipped++;
            }

            // ── Create Tracker Row in Circle B ────────────────────────────
            try {
                TrackerRow::create([
                    'circle_id'               => $circleB->id,
                    'project_id'              => $projectId,
                    'docket_number'           => $docket ?: null,
                    'client_name'             => $clientName,
                    'record_type'             => $recordType,
                    'pcm'                     => $pcm ?: null,
                    'scm'                     => $scm ?: null,
                    'pr'                      => $pr ?: null,
                    'project_start_date'      => $startDate,
                    'status'                  => $status ?: null,
                    'delivery_due_date'       => $dueDate,
                    'payment_status'          => $payStatus,
                    'percentage_of_completion'=> $pct,
                    'uin'                     => $uin ?: null,
                    'sort_order'              => $sortOrder++,
                ]);
                $rowCreated++;
            } catch (\Exception $e) {
                $this->warn("  Tracker row skipped ({$clientName}): " . $e->getMessage());
            }
        }

        fclose($handle);
        $this->info("=== Projects: created/updated $projCreated | skipped $projSkipped ===");
        $this->info("=== Tracker rows (Circle B): $rowCreated created ===");
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private function parseDate(string $raw): ?Carbon
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        try {
            // "12/Feb/26" → "12 Feb 2026"
            if (preg_match('/^(\d{1,2})\/([A-Za-z]+)\/(\d{2})$/', $raw, $m)) {
                return Carbon::createFromFormat('d M Y', "{$m[1]} {$m[2]} 20{$m[3]}");
            }
            // "May/20/2026" → "20 May 2026"
            if (preg_match('/^([A-Za-z]+)\/(\d{1,2})\/(\d{4})$/', $raw, $m)) {
                return Carbon::createFromFormat('d M Y', "{$m[2]} {$m[1]} {$m[3]}");
            }
            // "27-May-2026 17:12:45"
            if (preg_match('/\d{2}-[A-Za-z]+-\d{4}/', $raw)) {
                return Carbon::createFromFormat('d-M-Y H:i:s', $raw);
            }
            return Carbon::parse($raw);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function resolveUser(string $nameOrEmail, bool $byEmail = false): ?User
    {
        if ($nameOrEmail === '') return null;

        // Direct email lookup
        if ($byEmail || str_contains($nameOrEmail, '@')) {
            return $this->userMap[$nameOrEmail]
                ?? User::where('email', $nameOrEmail)->first();
        }

        // Already loaded map - try partial name match
        foreach ($this->userMap as $email => $user) {
            $short = strtolower(explode(' ', $user->name)[0]);
            if (str_contains(strtolower($nameOrEmail), $short)) return $user;
        }

        // Try DB by partial name
        return User::whereRaw('LOWER(name) LIKE ?', ['%' . strtolower(explode(' ', trim($nameOrEmail))[0]) . '%'])->first();
    }
}
