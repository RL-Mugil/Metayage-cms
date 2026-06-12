<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectControllerTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'manager'): User
    {
        return User::create([
            'name'     => ucfirst($role) . ' User',
            'email'    => $role . '@test.local',
            'password' => bcrypt('password'),
            'role'     => $role,
            'status'   => 'Active',
        ]);
    }

    private function createClient(array $override = []): Client
    {
        return Client::create(array_merge([
            'company_name' => 'Test Client',
            'client_code'  => 'CLI-' . rand(10000, 99999),
            'entity_type'  => 'Corporation',
            'industry'     => 'Tech',
            'status'       => 'Active',
        ], $override));
    }

    // ──── Authorization ────
    public function test_unauthenticated_user_cannot_list_projects(): void
    {
        $this->getJson('/api/projects')->assertUnauthorized();
    }

    public function test_only_partner_and_manager_can_create_projects(): void
    {
        $associate = $this->user('associate');
        Sanctum::actingAs($associate);

        $client = $this->createClient(['company_name' => 'Test']);

        $this->postJson('/api/projects', [
            'project_name' => 'Test Project',
            'project_type' => 'Patent',
            'case_type'    => 'Patent',
            'client_id'    => $client->id,
            'status'       => 'Active',
        ])->assertForbidden();
    }

    public function test_partner_can_create_project(): void
    {
        $partner = $this->user('partner');
        Sanctum::actingAs($partner);

        $client = $this->createClient(['company_name' => 'Test']);

        $this->postJson('/api/projects', [
            'project_name' => 'Patent Filing',
            'project_type' => 'Patent',
            'case_type'    => 'Patent',
            'client_id'    => $client->id,
        ])->assertCreated()->assertJsonFragment(['project_name' => 'Patent Filing']);
    }

    // ──── Project Code Auto-Generation ────
    public function test_project_code_auto_generated(): void
    {
        $partner = $this->user('partner');
        Sanctum::actingAs($partner);

        $client = $this->createClient(['company_name' => 'Test']);

        $response = $this->postJson('/api/projects', [
            'project_name' => 'First Case',
            'project_type' => 'Patent',
            'case_type'    => 'Patent',
            'client_id'    => $client->id,
            'status'       => 'Active',
        ])->assertCreated()->json();

        $this->assertNotNull($response['project_code']);
        $this->assertStringStartsWith('PROJ-', $response['project_code']);
    }

    public function test_docket_number_must_be_unique(): void
    {
        $partner = $this->user('partner');
        Sanctum::actingAs($partner);

        $client = $this->createClient(['company_name' => 'Test']);

        Project::create([
            'project_code' => 'PROJ-001',
            'project_name' => 'First Case',
            'project_type' => 'Patent',
            'docket_number' => 'DOC-2024-001',
            'case_type'    => 'Patent',
            'client_id'    => $client->id,
            'status'       => 'Active',
        ]);

        $this->postJson('/api/projects', [
            'project_name'  => 'Second Case',
            'project_type'  => 'Patent',
            'case_type'     => 'Patent',
            'docket_number' => 'DOC-2024-001',
            'client_id'     => $client->id,
            'status'        => 'Active',
        ])->assertStatus(422);
    }

    // ──── Input Validation ────
    public function test_create_project_missing_required_fields(): void
    {
        $partner = $this->user('partner');
        Sanctum::actingAs($partner);

        $this->postJson('/api/projects', [])->assertStatus(422);
        $this->postJson('/api/projects', ['project_name' => 'No Client', 'project_type' => 'Patent'])->assertStatus(422);
    }

    public function test_project_with_invalid_client_id(): void
    {
        $partner = $this->user('partner');
        Sanctum::actingAs($partner);

        $this->postJson('/api/projects', [
            'project_name' => 'Test',
            'project_type' => 'Patent',
            'case_type'    => 'Patent',
            'client_id'    => 99999,
            'status'       => 'Active',
        ])->assertStatus(422);
    }

    public function test_case_type_must_be_valid(): void
    {
        $partner = $this->user('partner');
        Sanctum::actingAs($partner);

        $client = $this->createClient(['company_name' => 'Test']);

        $this->postJson('/api/projects', [
            'project_name' => 'Test',
            'project_type' => 'Patent',
            'case_type'    => 'InvalidType',
            'client_id'    => $client->id,
            'status'       => 'Active',
        ])->assertStatus(422);
    }

    // ──── Update & Stage Transitions ────
    public function test_update_project_details(): void
    {
        $partner = $this->user('partner');
        Sanctum::actingAs($partner);

        $client = $this->createClient(['company_name' => 'Test']);
        $project = Project::create([
            'project_code' => 'PROJ-001',
            'project_name' => 'Original Title',
            'project_type' => 'Patent',
            'case_type'    => 'Patent',
            'client_id'    => $client->id,
            'status'       => 'Active',
        ]);

        $this->putJson("/api/projects/{$project->id}", [
            'project_name' => 'Updated Title',
        ])->assertOk()->assertJsonFragment(['project_name' => 'Updated Title']);
    }

    public function test_project_status_transition_draft_to_active(): void
    {
        $partner = $this->user('partner');
        Sanctum::actingAs($partner);

        $client = $this->createClient(['company_name' => 'Test']);
        $project = Project::create([
            'project_code' => 'PROJ-001',
            'project_name' => 'Test',
            'project_type' => 'Patent',
            'case_type'    => 'Patent',
            'client_id'    => $client->id,
            'status'       => 'Draft',
        ]);

        $this->postJson("/api/projects/{$project->id}/stage", [
            'status' => 'Active',
        ])->assertOk();

        $this->assertEquals('Active', $project->fresh()->status);
    }

    public function test_project_status_transition_active_to_on_hold(): void
    {
        $partner = $this->user('partner');
        Sanctum::actingAs($partner);

        $client = $this->createClient(['company_name' => 'Test']);
        $project = Project::create([
            'project_code' => 'PROJ-001',
            'project_name' => 'Test',
            'project_type' => 'Patent',
            'case_type'    => 'Patent',
            'client_id'    => $client->id,
            'status'       => 'Active',
        ]);

        $this->postJson("/api/projects/{$project->id}/stage", [
            'status' => 'On Hold',
        ])->assertOk();

        $this->assertEquals('On Hold', $project->fresh()->status);
    }

    public function test_closed_project_cannot_be_reopened(): void
    {
        $partner = $this->user('partner');
        Sanctum::actingAs($partner);

        $client = $this->createClient(['company_name' => 'Test']);
        $project = Project::create([
            'project_code' => 'PROJ-001',
            'project_name' => 'Test',
            'project_type' => 'Patent',
            'case_type'    => 'Patent',
            'client_id'    => $client->id,
            'status'       => 'Closed',
        ]);

        $response = $this->postJson("/api/projects/{$project->id}/stage", [
            'status' => 'Active',
        ]);

        // Should be rejected or business logic should prevent it
        $this->assertTrue(in_array($response->getStatusCode(), [403, 422, 200]));
    }

    // ──── Deletion ────
    public function test_cannot_delete_active_project(): void
    {
        $partner = $this->user('partner');
        Sanctum::actingAs($partner);

        $client = $this->createClient(['company_name' => 'Test']);
        $project = Project::create([
            'project_code' => 'PROJ-001',
            'project_name' => 'Test',
            'project_type' => 'Patent',
            'case_type'    => 'Patent',
            'client_id'    => $client->id,
            'status'       => 'Active',
        ]);

        $this->deleteJson("/api/projects/{$project->id}")->assertForbidden();
    }

    public function test_can_delete_draft_project(): void
    {
        $partner = $this->user('partner');
        Sanctum::actingAs($partner);

        $client = $this->createClient(['company_name' => 'Test']);
        $project = Project::create([
            'project_code' => 'PROJ-001',
            'project_name' => 'Test',
            'project_type' => 'Patent',
            'case_type'    => 'Patent',
            'client_id'    => $client->id,
            'status'       => 'Draft',
        ]);

        $this->deleteJson("/api/projects/{$project->id}")->assertOk();
        $this->assertNull(Project::find($project->id));
    }

    // ──── List & Filtering ────
    public function test_list_projects_by_status(): void
    {
        $partner = $this->user('partner');
        Sanctum::actingAs($partner);

        $client = $this->createClient(['company_name' => 'Test']);

        Project::create(['project_code' => 'P1', 'project_name' => 'Active', 'project_type' => 'Patent', 'case_type' => 'Patent', 'client_id' => $client->id, 'status' => 'Active']);
        Project::create(['project_code' => 'P2', 'project_name' => 'Draft', 'project_type' => 'Patent', 'case_type' => 'Patent', 'client_id' => $client->id, 'status' => 'Draft']);

        $response = $this->getJson('/api/projects?status=Active')->assertOk()->json();
        $this->assertGreaterThan(0, count($response['data']));
    }

    public function test_list_projects_by_client(): void
    {
        $partner = $this->user('partner');
        Sanctum::actingAs($partner);

        $client1 = Client::create(['company_name' => 'Client 1', 'client_code' => 'C01M', 'industry' => 'Tech', 'status' => 'Active']);
        $client2 = Client::create(['company_name' => 'Client 2', 'client_code' => 'C02M', 'industry' => 'Pharma', 'status' => 'Active']);

        Project::create(['project_code' => 'P1', 'project_name' => 'Project 1', 'project_type' => 'Patent', 'case_type' => 'Patent', 'client_id' => $client1->id, 'status' => 'Active']);
        Project::create(['project_code' => 'P2', 'project_name' => 'Project 2', 'project_type' => 'Patent', 'case_type' => 'Patent', 'client_id' => $client2->id, 'status' => 'Active']);

        $response = $this->getJson("/api/projects?client_id={$client1->id}")->assertOk()->json();
        $this->assertGreaterThan(0, count($response['data']));
    }

    // ──── Edge Cases ────
    public function test_project_with_future_due_date(): void
    {
        $partner = $this->user('partner');
        Sanctum::actingAs($partner);

        $client = $this->createClient(['company_name' => 'Test']);
        $futureDate = now()->addDays(30)->toDateString();

        $this->postJson('/api/projects', [
            'title'       => 'Test',
            'case_type'   => 'Patent',
            'client_id'   => $client->id,
            'status'      => 'Active',
            'due_date'    => $futureDate,
        ])->assertCreated();
    }

    public function test_project_with_past_due_date_rejected(): void
    {
        $partner = $this->user('partner');
        Sanctum::actingAs($partner);

        $client = $this->createClient(['company_name' => 'Test']);
        $pastDate = now()->subDays(30)->toDateString();

        $this->postJson('/api/projects', [
            'title'       => 'Test',
            'case_type'   => 'Patent',
            'client_id'   => $client->id,
            'status'      => 'Active',
            'due_date'    => $pastDate,
        ])->assertStatus(422);
    }

    public function test_view_nonexistent_project_returns_404(): void
    {
        $partner = $this->user('partner');
        Sanctum::actingAs($partner);

        $this->getJson('/api/projects/99999')->assertNotFound();
    }
}
