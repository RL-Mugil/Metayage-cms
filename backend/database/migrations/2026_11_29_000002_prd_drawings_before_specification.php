<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Provisional drafting (PRD): drawings are prepared before the specification is drafted.
 * Swaps the two stages in the PRD templates and in existing PRD cases. On a case where the
 * specification is in progress but drawings are still pending, drawings become the current
 * stage (no dates are invented).
 */
return new class extends Migration
{
    private const SPEC = 'Provisional specification drafted';
    private const DRAWINGS = 'Drawings prepared';

    public function up(): void
    {
        $this->swap(self::DRAWINGS);
    }

    public function down(): void
    {
        $this->swap(self::SPEC);
    }

    /** Puts $first ahead of the other of the two stages wherever they appear in the wrong order. */
    private function swap(string $first): void
    {
        $second = $first === self::DRAWINGS ? self::SPEC : self::DRAWINGS;

        $templates = DB::table('jurisdiction_lifecycle_templates')->where('service_code', 'PRD')->pluck('id');
        foreach ($templates as $templateId) {
            $a = DB::table('jurisdiction_lifecycle_stages')->where('jurisdiction_lifecycle_template_id', $templateId)->where('stage_name', $first)->first();
            $b = DB::table('jurisdiction_lifecycle_stages')->where('jurisdiction_lifecycle_template_id', $templateId)->where('stage_name', $second)->first();
            if (! $a || ! $b || $a->sequence_order < $b->sequence_order) {
                continue;
            }
            // (template_id, sequence_order) is unique: move one out of the way first.
            DB::table('jurisdiction_lifecycle_stages')->where('id', $a->id)->update(['sequence_order' => 9999]);
            DB::table('jurisdiction_lifecycle_stages')->where('id', $b->id)->update(['sequence_order' => $a->sequence_order]);
            DB::table('jurisdiction_lifecycle_stages')->where('id', $a->id)->update(['sequence_order' => $b->sequence_order]);
        }

        $projectIds = DB::table('project_stages')->where('stage_name', $first)->pluck('project_id')->unique();
        foreach ($projectIds as $projectId) {
            $a = DB::table('project_stages')->where('project_id', $projectId)->where('stage_name', $first)->first();
            $b = DB::table('project_stages')->where('project_id', $projectId)->where('stage_name', $second)->first();
            if (! $a || ! $b || $a->sequence_order < $b->sequence_order) {
                continue;
            }
            DB::table('project_stages')->where('id', $a->id)->update(['sequence_order' => $b->sequence_order]);
            DB::table('project_stages')->where('id', $b->id)->update(['sequence_order' => $a->sequence_order]);
            if ($a->status === 'Pending' && $b->status === 'In Progress') {
                DB::table('project_stages')->where('id', $a->id)->update(['status' => 'In Progress']);
                DB::table('project_stages')->where('id', $b->id)->update(array_merge(['status' => 'Pending'],
                    ($b->start_source ?? null) === 'manual' ? [] : ['actual_start_at' => null, 'start_source' => null]));
            }
        }
    }
};
