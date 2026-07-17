<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DocketEvent;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\User;
use App\Services\InventionFamilyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_india_service_successors_are_gated_by_office_events(): void
    {
        [$provisional, $partner] = $this->matter('C77M001INPRV');
        app(InventionFamilyService::class)->attach($provisional);
        Sanctum::actingAs($partner);

        $complete = $this->postJson("/api/projects/{$provisional->id}/elevate", ['to_service' => 'CPT'])
            ->assertCreated()
            ->json('project');

        $this->assertSame('C77M001INCPT', $complete['docket_number']);
        $this->assertDatabaseHas('projects', [
            'id' => $complete['id'],
            'lifecycle_template_version' => '2026.2',
        ]);
        $this->assertDatabaseHas('project_stages', [
            'project_id' => $complete['id'],
            'stage_name' => 'Complete specification drafting',
        ]);

        $this->postJson("/api/projects/{$complete['id']}/elevate", ['to_service' => 'FER'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service_code');

        DocketEvent::create([
            'project_id' => $complete['id'],
            'patent_application_id' => Project::findOrFail($complete['id'])->patent_application_id,
            'event_type' => 'fer_received',
            'event_date' => now()->toDateString(),
            'created_by' => $partner->id,
        ]);

        $this->postJson("/api/projects/{$complete['id']}/elevate", ['to_service' => 'FER'])
            ->assertCreated()
            ->assertJsonPath('project.docket_number', 'C77M001INFER');
    }

    /** @return array{Project, User} */
    private function matter(string $docket): array
    {
        $partner = User::create([
            'name' => 'Partner', 'email' => 'service-transition@test.local',
            'password' => bcrypt('password'), 'role' => 'partner', 'status' => 'Active',
        ]);
        $client = Client::create(['client_code' => 'C77M', 'company_name' => 'Transition Client', 'status' => 'Active']);
        $project = Project::create([
            'project_code' => $docket, 'docket_number' => $docket, 'invention_number' => '001',
            'client_id' => $client->id, 'project_name' => 'Transition invention',
            'invention_title' => 'Transition invention', 'project_type' => 'Patent',
            'patent_office_code' => 'IN', 'service_code' => 'PRV',
            'assigned_partner_id' => $partner->id, 'status' => 'Open',
        ]);
        ProjectStage::create([
            'project_id' => $project->id, 'stage_name' => 'Matter opened',
            'status' => 'In Progress', 'sequence_order' => 0, 'duration_days' => 15,
        ]);

        return [$project, $partner];
    }
}
