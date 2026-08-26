<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Inventor role (Phase 3) — scoped by the project_inventors pivot, not by any
 * one Client, since an inventor can be inventor-of-record across multiple
 * different clients' cases (e.g. an outside professor).
 */
class InventorRoleTest extends TestCase
{
    use RefreshDatabase;

    private function client(string $code = '397M'): Client
    {
        return Client::create([
            'client_code' => $code, 'client_type' => 'organization',
            'legal_name' => "Client {$code}", 'company_name' => "Client {$code}",
            'nationality' => 'India', 'status' => 'Active', 'portal_enabled' => true,
        ]);
    }

    private function manager(): User
    {
        return User::create([
            'name' => 'Manager', 'email' => 'manager@firm.test',
            'password' => bcrypt('password'), 'role' => 'manager', 'status' => 'Active',
        ]);
    }

    public function test_staff_can_add_inventor_creating_a_new_login(): void
    {
        $client = $this->client();
        $project = Project::create([
            'project_code' => '397M001INPRV', 'docket_number' => '397M001INPRV',
            'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'Test Invention',
            'status' => 'Open', 'assigned_manager_id' => null,
        ]);
        $manager = $this->manager();
        $project->update(['assigned_manager_id' => $manager->id]);
        Sanctum::actingAs($manager);

        $response = $this->postJson("/api/projects/{$project->id}/inventors", [
            'name' => 'Prof. Sachin Garg', 'email' => 'sachin@iiit.test', 'password' => 'password123',
        ])->assertCreated()->json();

        $this->assertDatabaseHas('users', ['email' => 'sachin@iiit.test', 'role' => 'inventor']);
        $this->assertDatabaseHas('project_inventors', ['project_id' => $project->id, 'user_id' => $response['inventor']['id']]);
    }

    public function test_adding_existing_non_inventor_email_is_rejected(): void
    {
        $client = $this->client();
        $project = Project::create([
            'project_code' => '397M001INPRV', 'docket_number' => '397M001INPRV',
            'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'Test Invention',
            'status' => 'Open', 'assigned_manager_id' => null,
        ]);
        $manager = $this->manager();
        $project->update(['assigned_manager_id' => $manager->id]);
        Sanctum::actingAs($manager);

        $this->postJson("/api/projects/{$project->id}/inventors", [
            'name' => 'Manager', 'email' => 'manager@firm.test', 'password' => 'password123',
        ])->assertStatus(422);
    }

    public function test_inventor_only_sees_own_cases_across_clients_and_dashboard_scopes_by_pivot(): void
    {
        $clientA = $this->client('397M');
        $clientB = $this->client('269M');

        $ownCase = Project::create([
            'project_code' => '397M001INPRV', 'docket_number' => '397M001INPRV',
            'client_id' => $clientA->id, 'project_type' => 'Patent', 'project_name' => 'My Invention',
            'status' => 'Open',
        ]);
        ProjectStage::create(['project_id' => $ownCase->id, 'stage_name' => 'Prior Art Search In Progress', 'status' => 'In Progress', 'sequence_order' => 0]);

        $otherCase = Project::create([
            'project_code' => '269M001INFER', 'docket_number' => '269M001INFER',
            'client_id' => $clientB->id, 'project_type' => 'Patent', 'project_name' => 'Someone Else\'s Case',
            'status' => 'Open',
        ]);

        $inventor = User::create([
            'name' => 'Prof. Sachin Garg', 'email' => 'sachin@iiit.test',
            'password' => bcrypt('password'), 'role' => 'inventor', 'status' => 'Active',
        ]);
        $ownCase->inventors()->attach($inventor->id);

        Sanctum::actingAs($inventor);

        // Dashboard scoped to only the inventor's own case.
        $metrics = $this->getJson('/api/dashboard/metrics')->assertOk()->json()['metrics'];
        $this->assertCount(1, $metrics['action_items']);
        $this->assertSame('397M001INPRV', $metrics['action_items'][0]['docket_number']);

        // Projects list API also scoped — cannot see the other client's case.
        $ids = collect($this->getJson('/api/projects?per_page=100')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($ownCase->id));
        $this->assertFalse($ids->contains($otherCase->id));

        // Direct view of the other case is forbidden.
        $this->getJson("/api/projects/{$otherCase->id}")->assertForbidden();

        // Restricted to the dashboard + own-case pages only.
        $this->get('/financial')->assertRedirect('/');
        $this->get('/projects')->assertRedirect('/');
    }
}
