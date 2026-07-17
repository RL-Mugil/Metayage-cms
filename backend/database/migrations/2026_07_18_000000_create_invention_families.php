<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invention_families', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('invention_number', 3);
            $table->string('title');
            $table->date('earliest_priority_date')->nullable();
            $table->string('status')->default('Active')->index();
            $table->timestamps();
            $table->unique(['firm_id', 'client_id', 'invention_number'], 'invention_family_identity_unique');
        });

        Schema::table('patent_applications', function (Blueprint $table): void {
            $table->foreignId('invention_family_id')->nullable()->after('firm_id')
                ->constrained('invention_families')->nullOnDelete();
            $table->index(['invention_family_id', 'jurisdiction']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignId('invention_family_id')->nullable()->after('firm_id')
                ->constrained('invention_families')->nullOnDelete();
            $table->index(['invention_family_id', 'patent_office_code']);
        });

        Schema::create('family_migration_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason');
            $table->json('snapshot');
            $table->string('status')->default('Open')->index();
            $table->timestamps();
            $table->unique('project_id');
        });

        $this->backfillFamilies();
    }

    private function backfillFamilies(): void
    {
        DB::table('projects')->orderBy('id')->chunk(200, function ($projects): void {
            foreach ($projects as $project) {
                if (! preg_match('/^\d{3}$/', (string) $project->invention_number)) {
                    DB::table('family_migration_exceptions')->insertOrIgnore([
                        'firm_id' => $project->firm_id,
                        'project_id' => $project->id,
                        'reason' => 'Missing or invalid canonical invention number',
                        'snapshot' => json_encode(['docket_number' => $project->docket_number, 'client_id' => $project->client_id]),
                        'status' => 'Open',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    continue;
                }

                $familyId = DB::table('invention_families')
                    ->where('firm_id', $project->firm_id)
                    ->where('client_id', $project->client_id)
                    ->where('invention_number', $project->invention_number)
                    ->value('id');

                if (! $familyId) {
                    $familyId = DB::table('invention_families')->insertGetId([
                        'firm_id' => $project->firm_id,
                        'client_id' => $project->client_id,
                        'invention_number' => $project->invention_number,
                        'title' => $project->invention_title ?: $project->project_name,
                        'earliest_priority_date' => $project->priority_date,
                        'status' => 'Active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } elseif ($project->priority_date) {
                    DB::table('invention_families')->where('id', $familyId)
                        ->where(fn ($query) => $query->whereNull('earliest_priority_date')->orWhere('earliest_priority_date', '>', $project->priority_date))
                        ->update(['earliest_priority_date' => $project->priority_date, 'updated_at' => now()]);
                }

                DB::table('projects')->where('id', $project->id)->update(['invention_family_id' => $familyId]);
                if ($project->patent_application_id) {
                    DB::table('patent_applications')->where('id', $project->patent_application_id)
                        ->whereNull('invention_family_id')->update(['invention_family_id' => $familyId]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_migration_exceptions');
        Schema::table('projects', fn (Blueprint $table) => $table->dropConstrainedForeignId('invention_family_id'));
        Schema::table('patent_applications', fn (Blueprint $table) => $table->dropConstrainedForeignId('invention_family_id'));
        Schema::dropIfExists('invention_families');
    }
};
