<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inventor;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Inventor portal role — an inventor record (inventors) given a login (user_id) sees
 * only the cases it is named on, across clients (e.g. an outside professor).
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

    public function test_manager_gives_an_inventor_portal_access(): void
    {
        $inventor = Inventor::create(['full_name' => 'GARG Sachin']);
        Sanctum::actingAs($this->manager());

        $response = $this->postJson("/api/inventors/{$inventor->id}/portal-access", [
            'email' => 'sachin@iiit.test', 'password' => 'password123',
        ])->assertCreated()->json();

        $this->assertDatabaseHas('users', ['email' => 'sachin@iiit.test', 'role' => 'inventor']);
        $this->assertSame($response['user']['id'], $inventor->fresh()->user_id);

        // A second login for the same inventor is refused.
        $this->postJson("/api/inventors/{$inventor->id}/portal-access", [
            'email' => 'other@iiit.test', 'password' => 'password123',
        ])->assertStatus(422);
    }

    public function test_portal_access_uses_the_inventors_saved_email(): void
    {
        $inventor = Inventor::create(['full_name' => 'GARG Sachin', 'email' => 'sachin@iiit.test']);
        $noEmail = Inventor::create(['full_name' => 'No Email']);
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/inventors/{$inventor->id}/portal-access", ['password' => 'password123'])->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'sachin@iiit.test', 'role' => 'inventor', 'id' => $inventor->fresh()->user_id]);

        $this->postJson("/api/inventors/{$noEmail->id}/portal-access", ['password' => 'password123'])
            ->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_portal_access_with_an_existing_email_is_rejected(): void
    {
        $inventor = Inventor::create(['full_name' => 'GARG Sachin']);
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/inventors/{$inventor->id}/portal-access", [
            'email' => 'manager@firm.test', 'password' => 'password123',
        ])->assertStatus(422);
        $this->assertNull($inventor->fresh()->user_id);
    }

    public function test_paralegal_cannot_give_portal_access(): void
    {
        $inventor = Inventor::create(['full_name' => 'GARG Sachin']);
        Sanctum::actingAs(User::create([
            'name' => 'Para', 'email' => 'para@firm.test', 'password' => bcrypt('password'), 'role' => 'paralegal', 'status' => 'Active',
        ]));

        $this->postJson("/api/inventors/{$inventor->id}/portal-access", [
            'email' => 'sachin@iiit.test', 'password' => 'password123',
        ])->assertForbidden();
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

        $login = User::create([
            'name' => 'Prof. Sachin Garg', 'email' => 'sachin@iiit.test',
            'password' => bcrypt('password'), 'role' => 'inventor', 'status' => 'Active',
        ]);
        $record = Inventor::create(['full_name' => 'GARG Sachin', 'user_id' => $login->id]);
        $ownCase->syncInventors([$record->id]);
        // An inventor without a login on the other case gives this user no access to it.
        $otherCase->syncInventors([Inventor::create(['full_name' => 'Someone Else'])->id]);

        Sanctum::actingAs($login);

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

        // On their own case they see co-inventor names, never addresses or phone numbers.
        $this->getJson("/api/projects/{$ownCase->id}/inventors")->assertOk()
            ->assertJsonPath('0.full_name', 'GARG Sachin')->assertJsonMissingPath('0.city')->assertJsonMissingPath('0.phone');
        $this->getJson("/api/projects/{$otherCase->id}/inventors")->assertForbidden();

        // Restricted to the dashboard + own-case pages only.
        $this->get('/financial')->assertRedirect('/');
        $this->get('/projects')->assertRedirect('/');
    }
}
