<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\JurisdictionLifecycleTemplate;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A case's workflow follows its service code: changing the service rebuilds the stages (intake progress carried). */
class ServiceChangeTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Client::create(['client_code' => 'B37M', 'client_type' => 'organization', 'legal_name' => 'Client B37', 'company_name' => 'Client B37',
            'nationality' => 'India', 'status' => 'Active']);
        Sanctum::actingAs(User::create(['name' => 'Partner', 'email' => 'p@svc.test', 'password' => bcrypt('x'), 'role' => 'partner', 'status' => 'Active']));
    }

    private function createCase(string $docket, string $service): Project
    {
        $id = $this->postJson('/api/projects', [
            'record_mode' => 'existing', 'project_code' => $docket, 'docket_number' => $docket, 'client_id' => $this->client->id,
            'project_name' => "Case {$docket}", 'project_type' => 'Patent', 'patent_office_code' => 'IN', 'service_code' => $service,
        ])->assertCreated()->json('id');

        return Project::with('stages')->findOrFail($id);
    }

    /** Walk a case to the given stage the way a user does (stage update). */
    private function moveTo(Project $project, string $stage): void
    {
        $this->postJson("/api/projects/{$project->id}/stage", ["stage_name" => $stage])->assertOk();
    }

    public function test_pas_edited_to_prv_rebuilds_the_workflow_and_carries_intake(): void
    {
        $case = $this->createCase('B37M001INPAS', 'PAS');
        $this->assertSame('PAS', JurisdictionLifecycleTemplate::find($case->jurisdiction_lifecycle_template_id)->service_code);
        $this->moveTo($case, 'Disclosure Received');

        $preview = $this->getJson("/api/projects/{$case->id}/service-change-preview?office=IN&service=PRV")->assertOk()->json();
        $this->assertSame('B37M001INPRV', $preview['docket']);
        $this->assertSame('Provisional specification drafting', $preview['lands_on']);
        $this->assertSame('Disclosure Received', $case->fresh()->stages()->where('status', 'In Progress')->value('stage_name'));   // preview saved nothing

        $this->putJson("/api/projects/{$case->id}", ['service_code' => 'PRV', 'patent_office_code' => 'IN'])->assertOk();
        $case = $case->fresh();
        $this->assertSame('B37M001INPRV', $case->docket_number);
        $this->assertSame('PRV', JurisdictionLifecycleTemplate::find($case->jurisdiction_lifecycle_template_id)->service_code);
        $stages = $case->stages()->orderBy('sequence_order')->get();
        $this->assertSame('Completed', $stages[0]->status);
        $this->assertSame('Provisional specification drafting', $stages[1]->stage_name);
        $this->assertSame('In Progress', $stages[1]->status);
        $this->assertSame(1, $stages->where('status', 'In Progress')->count());
        $this->assertNotNull($case->patent_application_id);
        $this->assertSame(1, DB::table('project_stage_migration_snapshots')->where('project_id', $case->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'service_changed', 'subject_id' => $case->id]);

        // An edit that keeps the service leaves the stages alone.
        $this->putJson("/api/projects/{$case->id}", ['service_code' => 'PRV', 'patent_office_code' => 'IN', 'project_name' => 'Renamed'])->assertOk();
        $this->assertSame(1, DB::table('project_stage_migration_snapshots')->where('project_id', $case->id)->count());
    }

    public function test_a_granted_case_keeps_every_stage_complete_and_a_docket_clash_is_refused(): void
    {
        $case = $this->createCase('B37M002INFER', 'FER');
        $case->update(['status' => 'Granted']);
        $this->putJson("/api/projects/{$case->id}", ['service_code' => 'GRT', 'patent_office_code' => 'IN'])->assertOk();
        $stages = $case->fresh()->stages;
        $this->assertTrue($stages->isNotEmpty());
        $this->assertSame($stages->count(), $stages->where('status', 'Completed')->count());

        $this->createCase('B37M003INPRV', 'PRV');
        $other = $this->createCase('B37M003INPAS', 'PAS');
        $before = $other->stages()->pluck('stage_name')->all();
        $this->putJson("/api/projects/{$other->id}", ['service_code' => 'PRV', 'patent_office_code' => 'IN'])->assertStatus(422);
        $this->assertSame('PAS', $other->fresh()->service_code);
        $this->assertSame($before, $other->fresh()->stages()->pluck('stage_name')->all());
    }

    public function test_sync_command_fixes_imported_cases_whose_service_code_disagrees(): void
    {
        // Imported shape: service code CPT, but docket and stages still from PAS.
        $case = $this->createCase('B37M004INPAS', 'PAS');
        $case->forceFill(['service_code' => 'CPT'])->saveQuietly();
        $clash = $this->createCase('B37M005INPAS', 'PAS');
        $this->createCase('B37M005INFER', 'FER');
        $clash->forceFill(['service_code' => 'FER'])->saveQuietly();

        $this->artisan('projects:sync-service-workflow', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('B37M004INPAS', $case->fresh()->docket_number);

        $this->artisan('projects:sync-service-workflow')->assertSuccessful();
        $case = $case->fresh();
        $this->assertSame('B37M004INCPT', $case->docket_number);
        $this->assertSame('CPT', JurisdictionLifecycleTemplate::find($case->jurisdiction_lifecycle_template_id)->service_code);
        $this->assertSame('B37M005INPAS', $clash->fresh()->docket_number);   // clash skipped
    }

    public function test_only_people_who_can_edit_the_case_can_preview(): void
    {
        $case = $this->createCase('B37M006INPAS', 'PAS');
        Sanctum::actingAs(User::create(['name' => 'Assoc', 'email' => 'a@svc.test', 'password' => bcrypt('x'), 'role' => 'associate', 'status' => 'Active']));
        $this->getJson("/api/projects/{$case->id}/service-change-preview?office=IN&service=PRV")->assertForbidden();
    }
}
