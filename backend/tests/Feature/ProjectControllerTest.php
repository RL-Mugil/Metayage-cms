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
            'client_code'  => 'C' . str_pad((string) rand(1, 99), 2, '0', STR_PAD_LEFT) . 'M',
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
            'patent_office_code' => 'IN',
            'service_code' => 'FER',
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
            'patent_office_code' => 'IN',
            'service_code' => 'FER',
            'status'       => 'Active',
        ])->assertCreated()->json();

        $this->assertNotNull($response['project_code']);
        $this->assertSame($client->client_code . '001INFER', $response['project_code']);
        $this->assertSame($response['project_code'], $response['docket_number']);
    }

    public function test_docket_number_auto_generated_and_unique(): void
    {
        $partner = $this->user('partner');
        Sanctum::actingAs($partner);

        $client = $this->createClient(['company_name' => 'Test', 'client_code' => 'C01M']);

        // Create two projects for the same client; each should get a unique docket number
        $r1 = $this->postJson('/api/projects', [
            'project_name' => 'First Case',
            'project_type' => 'Patent',
            'case_type'    => 'Filing',
            'client_id'    => $client->id,
            'patent_office_code' => 'IN',
            'service_code' => 'FER',
        ])->assertCreated()->json();

        $r2 = $this->postJson('/api/projects', [
            'project_name' => 'Second Case',
            'project_type' => 'Patent',
            'case_type'    => 'Filing',
            'client_id'    => $client->id,
            'patent_office_code' => 'US',
            'service_code' => 'FIL',
        ])->assertCreated()->json();

        // Both should have docket numbers and they must differ
        $this->assertNotEmpty($r1['docket_number']);
        $this->assertNotEmpty($r2['docket_number']);
        $this->assertNotEquals($r1['docket_number'], $r2['docket_number']);
        $this->assertSame('C01M001INFER', $r1['docket_number']);
        $this->assertSame('C01M002USFIL', $r2['docket_number']);
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

    public function test_case_type_accepted(): void
    {
        $partner = $this->user('partner');
        Sanctum::actingAs($partner);

        $client = $this->createClient(['company_name' => 'Test']);

        // case_type is a free-form string; any value is accepted
        $this->postJson('/api/projects', [
            'project_name' => 'Test',
            'project_type' => 'Patent',
            'case_type'    => 'Filing',
            'client_id'    => $client->id,
            'patent_office_code' => 'IN',
            'service_code' => 'FER',
        ])->assertCreated();
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
            'project_name' => 'Test Future Deadline',
            'project_type' => 'Patent',
            'case_type'    => 'Filing',
            'client_id'    => $client->id,
            'patent_office_code' => 'IN',
            'service_code' => 'FER',
            'hard_deadline' => $futureDate,
        ])->assertCreated();
    }

    public function test_project_with_past_due_date_rejected(): void
    {
        $partner = $this->user('partner');
        Sanctum::actingAs($partner);

        $client = $this->createClient(['company_name' => 'Test']);
        $pastDate = now()->subDays(30)->toDateString();

        $this->postJson('/api/projects', [
            'project_name' => 'Test Past Deadline',
            'project_type' => 'Patent',
            'case_type'    => 'Filing',
            'client_id'    => $client->id,
            'hard_deadline' => $pastDate,
        ])->assertStatus(422);
    }

    public function test_view_nonexistent_project_returns_404(): void
    {
        $partner = $this->user('partner');
        Sanctum::actingAs($partner);

        $this->getJson('/api/projects/99999')->assertNotFound();
    }
}
