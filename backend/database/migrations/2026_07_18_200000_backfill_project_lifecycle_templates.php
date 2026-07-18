<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_stage_migration_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('migration_version', 32);
            $table->json('previous_stages');
            $table->json('mapping_summary');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['project_id', 'migration_version']);
        });

        Schema::create('lifecycle_migration_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('migration_version', 32);
            $table->string('reason');
            $table->json('context')->nullable();
            $table->string('status', 24)->default('Open');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['project_id', 'migration_version']);
        });

        $templates = DB::table('jurisdiction_lifecycle_templates')
            ->where('version', '2026.1')->where('is_active', true)->get()->keyBy('jurisdiction');
        $defaultReviewer = DB::table('users')->where('role', 'super_admin')->where('status', 'Active')->value('id');

        DB::table('projects')->whereNull('deleted_at')->orderBy('id')->chunkById(100, function ($projects) use ($templates, $defaultReviewer): void {
            foreach ($projects as $project) {
                $office = strtoupper((string) ($project->patent_office_code ?: 'IN'));
                $template = $templates->get($office);
                if (! $template) {
                    DB::table('lifecycle_migration_exceptions')->insert([
                        'project_id' => $project->id,
                        'migration_version' => '2026.1',
                        'reason' => 'No active jurisdiction lifecycle template.',
                        'context' => json_encode(['patent_office_code' => $office, 'service_code' => $project->service_code]),
                    ]);
                    continue;
                }

                $oldStages = DB::table('project_stages')->where('project_id', $project->id)->orderBy('sequence_order')->get();
                $templateStages = DB::table('jurisdiction_lifecycle_stages')
                    ->where('jurisdiction_lifecycle_template_id', $template->id)->orderBy('sequence_order')->get();
                if ($templateStages->isEmpty()) {
                    DB::table('lifecycle_migration_exceptions')->insert([
                        'project_id' => $project->id,
                        'migration_version' => '2026.1',
                        'reason' => 'Lifecycle template has no stages.',
                    ]);
                    continue;
                }

                $completed = $oldStages->where('status', 'Completed')->count();
                $mappedCompleted = $oldStages->isEmpty()
                    ? 0
                    : min($templateStages->count(), (int) floor(($completed / $oldStages->count()) * $templateStages->count()));
                if (in_array($project->status, ['Completed', 'Granted', 'Closed'], true)) {
                    $mappedCompleted = $templateStages->count();
                }

                DB::table('project_stage_migration_snapshots')->insert([
                    'project_id' => $project->id,
                    'migration_version' => '2026.1',
                    'previous_stages' => json_encode($oldStages),
                    'mapping_summary' => json_encode([
                        'old_count' => $oldStages->count(),
                        'old_completed' => $completed,
                        'new_count' => $templateStages->count(),
                        'new_completed' => $mappedCompleted,
                        'method' => 'completion_ratio',
                    ]),
                ]);

                DB::table('project_stages')->where('project_id', $project->id)->delete();
                foreach ($templateStages as $index => $stage) {
                    $status = $index < $mappedCompleted ? 'Completed'
                        : ($index === $mappedCompleted && $mappedCompleted < $templateStages->count() ? 'In Progress' : 'Pending');
                    DB::table('project_stages')->insert([
                        'project_id' => $project->id,
                        'stage_name' => $stage->stage_name,
                        'duration_days' => $stage->target_duration_days ?? 0,
                        'gate_criteria' => $stage->gate_criteria,
                        'sequence_order' => $index,
                        'status' => $status,
                        'actual_start_at' => $status !== 'Pending' ? now() : null,
                        'actual_end_at' => $status === 'Completed' ? now() : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('projects')->where('id', $project->id)->update([
                    'jurisdiction_lifecycle_template_id' => $template->id,
                    'lifecycle_template_version' => $template->version,
                    'docket_reviewer_id' => $project->docket_reviewer_id
                        ?: $project->assigned_manager_id ?: $project->assigned_partner_id ?: $defaultReviewer,
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        $snapshots = DB::table('project_stage_migration_snapshots')->where('migration_version', '2026.1')->get();
        foreach ($snapshots as $snapshot) {
            DB::table('project_stages')->where('project_id', $snapshot->project_id)->delete();
            foreach (json_decode($snapshot->previous_stages, true) ?: [] as $stage) {
                unset($stage['id']);
                DB::table('project_stages')->insert($stage);
            }
            DB::table('projects')->where('id', $snapshot->project_id)->update([
                'jurisdiction_lifecycle_template_id' => null,
                'lifecycle_template_version' => null,
                'docket_reviewer_id' => null,
            ]);
        }
        Schema::dropIfExists('lifecycle_migration_exceptions');
        Schema::dropIfExists('project_stage_migration_snapshots');
    }
};
