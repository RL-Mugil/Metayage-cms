<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jurisdiction_lifecycle_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('jurisdiction', 8);
            $table->string('service_code', 3)->default('*');
            $table->string('name');
            $table->string('version', 32);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['jurisdiction', 'service_code', 'version'], 'lifecycle_template_version_unique');
            $table->index(['jurisdiction', 'service_code', 'is_active'], 'lifecycle_template_lookup');
        });

        Schema::create('jurisdiction_lifecycle_stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('jurisdiction_lifecycle_template_id')
                ->constrained('jurisdiction_lifecycle_templates')->cascadeOnDelete();
            $table->string('stage_code', 48);
            $table->string('stage_name');
            $table->unsignedSmallInteger('sequence_order');
            $table->unsignedSmallInteger('target_duration_days')->nullable();
            $table->json('gate_criteria')->nullable();
            $table->timestamps();

            $table->unique(['jurisdiction_lifecycle_template_id', 'stage_code'], 'lifecycle_stage_code_unique');
            $table->unique(['jurisdiction_lifecycle_template_id', 'sequence_order'], 'lifecycle_stage_sequence_unique');
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignId('jurisdiction_lifecycle_template_id')->nullable()
                ->constrained('jurisdiction_lifecycle_templates')->nullOnDelete();
            $table->string('lifecycle_template_version', 32)->nullable();
            $table->foreignId('docket_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['patent_office_code', 'service_code', 'lifecycle_template_version'], 'project_lifecycle_lookup');
        });

        $this->seedTemplates();
    }

    private function seedTemplates(): void
    {
        $templates = [
            'IN' => ['India patent prosecution', [
                ['INTAKE', 'Instruction and conflict clearance'],
                ['DRAFT', 'Specification and filing documents'],
                ['FILE', 'Application filing'],
                ['PUBLICATION', 'Publication monitoring'],
                ['EXAMINATION', 'Examination request and prosecution'],
                ['DISPOSAL', 'Grant, refusal, withdrawal or abandonment'],
                ['POST_GRANT', 'Post-grant and renewal management'],
            ]],
            'WO' => ['PCT international phase', [
                ['INTAKE', 'International filing intake'],
                ['FILE', 'PCT filing and formalities'],
                ['SEARCH', 'International search monitoring'],
                ['PUBLICATION', 'International publication monitoring'],
                ['CHAPTER_II', 'Optional Chapter II examination'],
                ['NATIONAL_PHASE', 'National phase instructions and entry'],
                ['CLOSE', 'International phase closure'],
            ]],
            'US' => ['United States patent prosecution', [
                ['INTAKE', 'US filing intake'],
                ['FILE', 'Application filing and formalities'],
                ['PRE_EXAM', 'Pre-examination and publication monitoring'],
                ['EXAMINATION', 'USPTO examination and responses'],
                ['APPEAL', 'Appeal or continued examination'],
                ['ALLOWANCE', 'Allowance and issue processing'],
                ['POST_GRANT', 'Post-grant and maintenance management'],
            ]],
            'EP' => ['European patent prosecution', [
                ['INTAKE', 'European filing intake'],
                ['FILE', 'Application filing and formalities'],
                ['SEARCH', 'European search and response'],
                ['PUBLICATION', 'Publication monitoring'],
                ['EXAMINATION', 'Substantive examination'],
                ['GRANT', 'Intention to grant and grant'],
                ['VALIDATION', 'Validation, opposition and renewal management'],
            ]],
        ];

        foreach ($templates as $jurisdiction => [$name, $stages]) {
            $templateId = DB::table('jurisdiction_lifecycle_templates')->insertGetId([
                'jurisdiction' => $jurisdiction,
                'service_code' => '*',
                'name' => $name,
                'version' => '2026.1',
                'effective_from' => '2026-01-01',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($stages as $sequence => [$code, $stageName]) {
                DB::table('jurisdiction_lifecycle_stages')->insert([
                    'jurisdiction_lifecycle_template_id' => $templateId,
                    'stage_code' => $code,
                    'stage_name' => $stageName,
                    'sequence_order' => $sequence,
                    'gate_criteria' => json_encode([]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex('project_lifecycle_lookup');
            $table->dropConstrainedForeignId('docket_reviewer_id');
            $table->dropConstrainedForeignId('jurisdiction_lifecycle_template_id');
            $table->dropColumn('lifecycle_template_version');
        });
        Schema::dropIfExists('jurisdiction_lifecycle_stages');
        Schema::dropIfExists('jurisdiction_lifecycle_templates');
    }
};
