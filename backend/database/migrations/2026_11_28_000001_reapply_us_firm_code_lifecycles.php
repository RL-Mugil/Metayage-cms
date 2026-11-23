<?php

use App\Models\Project;
use App\Services\LifecycleReapplyService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * US cases created before the US templates per service code (2026_11_27_000002) are still
 * on the generic US list. Move each onto the template for its own code (old stages kept
 * in project_stage_migration_snapshots, version '2026.5-us'); the foreign filing
 * permission step is added where an inventor's nationality differs from the US.
 */
return new class extends Migration
{
    private const VERSION = '2026.5-us';

    public function up(): void
    {
        $genericUs = DB::table('jurisdiction_lifecycle_templates')->where('jurisdiction', 'US')->where('service_code', '*')->pluck('id');
        $service = app(LifecycleReapplyService::class);

        Project::query()->whereRaw('UPPER(patent_office_code) = ?', ['US'])
            ->where(fn ($q) => $q->whereIn('jurisdiction_lifecycle_template_id', $genericUs)->orWhereNull('jurisdiction_lifecycle_template_id'))
            ->orderBy('id')
            ->chunkById(100, function ($projects) use ($service) {
                foreach ($projects as $project) {
                    $service->reapply($project, self::VERSION);
                }
            });
    }

    public function down(): void
    {
        foreach (DB::table('project_stage_migration_snapshots')->where('migration_version', self::VERSION)->get() as $snapshot) {
            $summary = json_decode($snapshot->mapping_summary, true) ?: [];
            DB::table('project_stages')->where('project_id', $snapshot->project_id)->delete();
            foreach (json_decode($snapshot->previous_stages, true) ?: [] as $stage) {
                unset($stage['id']);
                DB::table('project_stages')->insert($stage);
            }
            $old = $summary['from_template_id'] ?? null;
            DB::table('projects')->where('id', $snapshot->project_id)->update([
                'jurisdiction_lifecycle_template_id' => $old,
                'lifecycle_template_version' => $old ? DB::table('jurisdiction_lifecycle_templates')->where('id', $old)->value('version') : null,
            ]);
        }
        DB::table('project_stage_migration_snapshots')->where('migration_version', self::VERSION)->delete();
    }
};
