<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rewrite each project's pipeline stages from the old 7-stage list
     * (Intake … Renewal) to the new 16-stage Patent Process Lifecycle.
     * The project's current position is preserved via a name mapping.
     */
    private const NEW_STAGES = [
        'Invention Disclosure', 'Patent Search', 'Search Report',
        'Provisional Application', 'Provisional Filing',
        'Patent Drafting', 'Applicant/Inventor Review', 'Filing with Patent Office',
        'First Examination Report', 'FER Response Preparation', 'FER Response Filing',
        'Hearing with Examiner', 'Hearing Response Preparation', 'Hearing Response Filing',
        'Granted', 'Renewal',
    ];

    private const OLD_TO_NEW = [
        'Intake'          => 'Invention Disclosure',
        'Drafting'        => 'Patent Drafting',
        'Filing'          => 'Filing with Patent Office',
        'Examination'     => 'First Examination Report',
        'Object received' => 'FER Response Preparation',
        'Granted'         => 'Granted',
        'Renewal'         => 'Renewal',
    ];

    public function up(): void
    {
        $projectIds = DB::table('projects')->pluck('id');

        foreach ($projectIds as $pid) {
            $stages = DB::table('project_stages')->where('project_id', $pid)->orderBy('sequence_order')->get();

            // Skip projects already on the new lifecycle (idempotent re-runs)
            if ($stages->contains(fn ($s) => $s->stage_name === 'Invention Disclosure')) {
                continue;
            }

            // Current stage = the In Progress one; else first Pending; else all done → Granted
            $current = $stages->firstWhere('status', 'In Progress')
                ?? $stages->firstWhere('status', 'Pending');
            $oldName = $current->stage_name ?? 'Granted';
            $newName = self::OLD_TO_NEW[$oldName] ?? 'Invention Disclosure';
            $newIdx  = array_search($newName, self::NEW_STAGES);
            if ($newIdx === false) $newIdx = 0;

            DB::table('project_stages')->where('project_id', $pid)->delete();

            $now  = now();
            $rows = [];
            foreach (self::NEW_STAGES as $i => $name) {
                $rows[] = [
                    'project_id'      => $pid,
                    'stage_name'      => $name,
                    'status'          => $i < $newIdx ? 'Completed' : ($i === $newIdx ? 'In Progress' : 'Pending'),
                    'sequence_order'  => $i,
                    'duration_days'   => 15,
                    'due_date'        => $now->copy()->addDays(($i + 1) * 15),
                    'actual_start_at' => $i === $newIdx ? $now : null,
                    'actual_end_at'   => $i < $newIdx ? $now : null,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];
            }
            DB::table('project_stages')->insert($rows);
        }
    }

    public function down(): void
    {
        // Not reversible — old per-project progress was replaced.
    }
};
