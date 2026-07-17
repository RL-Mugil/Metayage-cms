<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_transition_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('jurisdiction', 8);
            $table->string('from_service_code', 3);
            $table->string('to_service_code', 3);
            $table->string('required_event_type', 64)->nullable();
            $table->string('required_application_status', 48)->nullable();
            $table->string('description');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['jurisdiction', 'from_service_code', 'to_service_code'], 'service_transition_unique');
            $table->index(['jurisdiction', 'from_service_code', 'is_active'], 'service_transition_lookup');
        });

        Schema::create('service_lifecycle_migration_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('migration_version', 32);
            $table->json('previous_stages');
            $table->json('mapping_summary');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['project_id', 'migration_version']);
        });

        $templates = [
            'PRV' => ['India provisional filing', ['Instruction and invention disclosure', 'Provisional specification drafting', 'Internal legal review', 'Client filing approval', 'IPO forms and filing', 'Application number recorded', 'Service engagement closed']],
            'CPT' => ['India complete patent filing', ['Instruction and claim scope confirmation', 'Complete specification drafting', 'Internal legal review', 'Client filing approval', 'IPO forms and filing', 'Application number recorded', 'Service engagement closed']],
            'CPD' => ['India complete design filing', ['Instruction and design representation review', 'Design application preparation', 'Internal legal review', 'Client filing approval', 'IPO forms and filing', 'Application number recorded', 'Service engagement closed']],
            'FER' => ['India FER response', ['FER received and docketed', 'Objections analysed', 'Response and amendments drafted', 'Internal legal review', 'Client approval', 'FER response filed', 'Awaiting Controller review']],
            'SER' => ['India subsequent examination response', ['SER received and docketed', 'Objections analysed', 'Response and amendments drafted', 'Internal legal review', 'Client approval', 'SER response filed', 'Awaiting Controller review']],
            'TER' => ['India further examination response', ['TER received and docketed', 'Objections analysed', 'Response and amendments drafted', 'Internal legal review', 'Client approval', 'TER response filed', 'Awaiting Controller review']],
            'HRG' => ['India hearing service', ['Hearing notice docketed', 'Hearing strategy and evidence prepared', 'Written submissions drafted', 'Internal legal review', 'Client and counsel preparation', 'Hearing attended', 'Awaiting Controller decision']],
            'RNF' => ['India renewal fee service', ['Renewal instruction confirmed', 'Due date and fee verified', 'Client authority and funds confirmed', 'Renewal fee paid', 'IPO acknowledgement recorded', 'Next renewal monitored', 'Service engagement closed']],
        ];

        foreach ($templates as $serviceCode => [$name, $stages]) {
            $templateId = DB::table('jurisdiction_lifecycle_templates')->insertGetId([
                'jurisdiction' => 'IN', 'service_code' => $serviceCode, 'name' => $name,
                'version' => '2026.2', 'effective_from' => '2026-01-01', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($stages as $sequence => $stageName) {
                DB::table('jurisdiction_lifecycle_stages')->insert([
                    'jurisdiction_lifecycle_template_id' => $templateId,
                    'stage_code' => 'S'.str_pad((string) ($sequence + 1), 2, '0', STR_PAD_LEFT),
                    'stage_name' => $stageName,
                    'sequence_order' => $sequence,
                    'target_duration_days' => 0,
                    'gate_criteria' => json_encode([]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $transitions = [
            ['PRV', 'CPT', null, null, 'Convert provisional filing to complete patent filing.'],
            ['PRV', 'CPD', null, null, 'Convert provisional work to a complete design filing where instructed.'],
            ['CPT', 'FER', 'fer_received', null, 'Create FER response engagement after FER receipt.'],
            ['CPD', 'FER', 'fer_received', null, 'Create FER response engagement after FER receipt.'],
            ['FER', 'SER', 'ser_received', null, 'Create subsequent examination response after SER receipt.'],
            ['FER', 'TER', 'ter_received', null, 'Create further examination response after TER receipt.'],
            ['FER', 'HRG', 'hearing_notice', null, 'Create hearing engagement after hearing notice.'],
            ['SER', 'TER', 'ter_received', null, 'Create further examination response after TER receipt.'],
            ['SER', 'HRG', 'hearing_notice', null, 'Create hearing engagement after hearing notice.'],
            ['TER', 'HRG', 'hearing_notice', null, 'Create hearing engagement after hearing notice.'],
            ['FER', 'RNF', null, 'Granted', 'Start renewal service after grant.'],
            ['SER', 'RNF', null, 'Granted', 'Start renewal service after grant.'],
            ['TER', 'RNF', null, 'Granted', 'Start renewal service after grant.'],
            ['HRG', 'RNF', null, 'Granted', 'Start renewal service after Controller grant.'],
            ['RNF', 'RNF', null, 'Granted', 'Create the next renewal service engagement.'],
        ];
        foreach ($transitions as [$from, $to, $event, $status, $description]) {
            DB::table('service_transition_rules')->insert([
                'jurisdiction' => 'IN', 'from_service_code' => $from, 'to_service_code' => $to,
                'required_event_type' => $event, 'required_application_status' => $status,
                'description' => $description, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->backfillExactIndianTemplates();
    }

    private function backfillExactIndianTemplates(): void
    {
        $templateByService = DB::table('jurisdiction_lifecycle_templates')
            ->where('jurisdiction', 'IN')->where('version', '2026.2')->get()->keyBy('service_code');

        DB::table('projects')->whereNull('deleted_at')->where('patent_office_code', 'IN')->orderBy('id')->chunkById(100, function ($projects) use ($templateByService): void {
            foreach ($projects as $project) {
                $template = $templateByService->get(strtoupper((string) $project->service_code));
                if (! $template) {
                    continue;
                }
                $oldStages = DB::table('project_stages')->where('project_id', $project->id)->orderBy('sequence_order')->get();
                $newStages = DB::table('jurisdiction_lifecycle_stages')->where('jurisdiction_lifecycle_template_id', $template->id)->orderBy('sequence_order')->get();
                $completed = $oldStages->where('status', 'Completed')->count();
                $mappedCompleted = $oldStages->isEmpty() ? 0 : min($newStages->count(), (int) floor(($completed / $oldStages->count()) * $newStages->count()));
                if (in_array($project->status, ['Completed', 'Granted', 'Closed'], true)) {
                    $mappedCompleted = $newStages->count();
                }
                DB::table('service_lifecycle_migration_snapshots')->insert([
                    'project_id' => $project->id, 'migration_version' => '2026.2',
                    'previous_stages' => json_encode($oldStages),
                    'mapping_summary' => json_encode([
                        'old_count' => $oldStages->count(),
                        'new_count' => $newStages->count(),
                        'mapped_completed' => $mappedCompleted,
                        'method' => 'completion_ratio',
                        'previous_template_id' => $project->jurisdiction_lifecycle_template_id,
                        'previous_template_version' => $project->lifecycle_template_version,
                    ]),
                ]);
                DB::table('project_stages')->where('project_id', $project->id)->delete();
                foreach ($newStages as $index => $stage) {
                    $state = $index < $mappedCompleted ? 'Completed' : ($index === $mappedCompleted && $mappedCompleted < $newStages->count() ? 'In Progress' : 'Pending');
                    DB::table('project_stages')->insert([
                        'project_id' => $project->id, 'stage_name' => $stage->stage_name,
                        'duration_days' => $stage->target_duration_days ?? 0, 'gate_criteria' => $stage->gate_criteria,
                        'sequence_order' => $index, 'status' => $state,
                        'actual_start_at' => $state !== 'Pending' ? now() : null, 'actual_end_at' => $state === 'Completed' ? now() : null,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                DB::table('projects')->where('id', $project->id)->update([
                    'jurisdiction_lifecycle_template_id' => $template->id,
                    'lifecycle_template_version' => $template->version,
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        $snapshots = DB::table('service_lifecycle_migration_snapshots')->where('migration_version', '2026.2')->get();
        foreach ($snapshots as $snapshot) {
            DB::table('project_stages')->where('project_id', $snapshot->project_id)->delete();
            foreach (json_decode($snapshot->previous_stages, true) ?: [] as $stage) {
                unset($stage['id']);
                DB::table('project_stages')->insert($stage);
            }
            $summary = json_decode($snapshot->mapping_summary, true) ?: [];
            DB::table('projects')->where('id', $snapshot->project_id)->update([
                'jurisdiction_lifecycle_template_id' => $summary['previous_template_id'] ?? null,
                'lifecycle_template_version' => $summary['previous_template_version'] ?? null,
                'updated_at' => now(),
            ]);
        }
        Schema::dropIfExists('service_lifecycle_migration_snapshots');
        Schema::dropIfExists('service_transition_rules');
        $templateIds = DB::table('jurisdiction_lifecycle_templates')->where('version', '2026.2')->pluck('id');
        DB::table('jurisdiction_lifecycle_stages')->whereIn('jurisdiction_lifecycle_template_id', $templateIds)->delete();
        DB::table('jurisdiction_lifecycle_templates')->whereIn('id', $templateIds)->delete();
    }
};
