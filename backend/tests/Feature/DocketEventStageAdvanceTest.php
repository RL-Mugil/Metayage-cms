<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Recording a docket event must auto-advance the matching stage in the
 * project's own "Prosecution lifecycle" stepper (ProjectStage), so the
 * client-visible view reflects what actually happened without a manual
 * stage-picker step. This must never reach into a sibling engagement's
 * stages — each service engagement runs its own lifecycle independently.
 */
class DocketEventStageAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private function partner(): User
    {
        return User::create([
            'name' => 'Partner User', 'email' => 'partner@stageadvance.test',
            'password' => bcrypt('password'), 'role' => 'partner', 'status' => 'Active',
        ]);
    }

    private function client(): Client
    {
        return Client::create([
            'client_code' => '397M', 'client_type' => 'organization',
            'legal_name' => 'Client 397', 'company_name' => 'Client 397',
            'nationality' => 'India', 'status' => 'Active',
        ]);
    }

    private function createProject(Client $client, string $docket, string $office, string $service): Project
    {
        $id = $this->postJson('/api/projects', [
            'record_mode' => 'existing',
            'project_code' => $docket,
            'docket_number' => $docket,
            'client_id' => $client->id,
            'project_name' => "Project {$docket}",
            'project_type' => 'Patent',
            'patent_office_code' => $office,
            'service_code' => $service,
        ])->assertCreated()->json('id');

        return Project::with('stages')->findOrFail($id);
    }

    public function test_matching_docket_event_completes_its_stage_and_starts_the_next_one(): void
    {
        Sanctum::actingAs($this->partner());
        $client = $this->client();
        $fer = $this->createProject($client, '397M007INFER', 'IN', 'FER');

        $this->assertSame('FER received and docketed', $fer->stages[0]->stage_name);
        $this->assertSame('In Progress', $fer->stages[0]->status);
        $this->assertSame('Pending', $fer->stages[1]->status);

        $this->postJson("/api/projects/{$fer->id}/docket/events", [
            'event_type' => 'fer_received', 'event_date' => '2026-08-26',
        ])->assertCreated();

        $fer = Project::with('stages')->findOrFail($fer->id);
        $this->assertSame('Completed', $fer->stages[0]->status);
        $this->assertNotNull($fer->stages[0]->actual_end_at);
        $this->assertSame('In Progress', $fer->stages[1]->status);
        $this->assertSame('Objections analysed', $fer->stages[1]->stage_name);
    }

    public function test_stage_advancement_never_touches_a_sibling_engagements_own_stages(): void
    {
        Sanctum::actingAs($this->partner());
        $client = $this->client();
        $fer = $this->createProject($client, '397M008INFER', 'IN', 'FER');
        $cpt = $this->createProject($client, '397M008INCPT', 'IN', 'CPT');

        $cptStagesBefore = $cpt->stages->pluck('status', 'stage_name');

        $this->postJson("/api/projects/{$fer->id}/docket/events", [
            'event_type' => 'fer_received', 'event_date' => '2026-08-26',
        ])->assertCreated();

        $cptStagesAfter = Project::with('stages')->findOrFail($cpt->id)->stages->pluck('status', 'stage_name');
        $this->assertEquals($cptStagesBefore, $cptStagesAfter);
    }

    public function test_event_with_no_matching_stage_trigger_leaves_stages_untouched(): void
    {
        Sanctum::actingAs($this->partner());
        $client = $this->client();
        $fer = $this->createProject($client, '397M009INFER', 'IN', 'FER');
        $before = $fer->stages->pluck('status', 'stage_name');

        // 'rfe_filed' has no gate_criteria mapping on the FER template.
        $this->postJson("/api/projects/{$fer->id}/docket/events", [
            'event_type' => 'rfe_filed', 'event_date' => '2026-08-26',
        ])->assertCreated();

        $after = Project::with('stages')->findOrFail($fer->id)->stages->pluck('status', 'stage_name');
        $this->assertEquals($before, $after);
    }
}
