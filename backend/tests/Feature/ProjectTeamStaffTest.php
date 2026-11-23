<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A case's attorney, client managers and patent engineer are firm employees only. */
class ProjectTeamStaffTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $email, 'password' => bcrypt('password'), 'role' => $role, 'status' => 'Active']);
    }

    private function payload(Client $client, string $docket, array $team = []): array
    {
        return [
            'record_mode' => 'existing', 'project_code' => $docket, 'docket_number' => $docket,
            'client_id' => $client->id, 'project_name' => "Project {$docket}", 'project_type' => 'Patent',
            'patent_office_code' => 'IN', 'service_code' => 'PRD',
        ] + $team;
    }

    public function test_team_fields_accept_employees_only(): void
    {
        $partner = $this->user('partner', 'partner@team.test');
        $paralegal = $this->user('paralegal', 'para@team.test');
        $portal = $this->user('client_admin', 'portal@client.test');
        $inventor = $this->user('inventor', 'inventor@client.test');
        Sanctum::actingAs($partner);
        $client = Client::create([
            'client_code' => '398M', 'client_type' => 'organization', 'legal_name' => 'Client 398',
            'company_name' => 'Client 398', 'nationality' => 'India', 'status' => 'Active',
        ]);

        foreach (['assigned_partner_id', 'assigned_manager_id', 'secondary_manager_id', 'patent_engineer_id'] as $field) {
            $this->postJson('/api/projects', $this->payload($client, '398M001INPRD', [$field => $portal->id]))
                ->assertStatus(422)->assertJsonValidationErrors($field);
            $this->postJson('/api/projects', $this->payload($client, '398M001INPRD', [$field => $inventor->id]))
                ->assertStatus(422)->assertJsonValidationErrors($field);
        }

        $id = $this->postJson('/api/projects', $this->payload($client, '398M001INPRD', [
            'assigned_partner_id' => $partner->id, 'assigned_manager_id' => $partner->id,
            'secondary_manager_id' => $paralegal->id, 'patent_engineer_id' => $paralegal->id,
        ]))->assertCreated()->json('id');

        // An older case that already has a non-employee keeps saving until it is changed.
        Project::whereKey($id)->update(['patent_engineer_id' => $portal->id]);
        $this->putJson("/api/projects/{$id}", ['patent_engineer_id' => $portal->id, 'project_name' => 'Renamed'])->assertOk();
        $this->putJson("/api/projects/{$id}", ['assigned_manager_id' => $inventor->id])->assertStatus(422)->assertJsonValidationErrors('assigned_manager_id');

        // The pickers' list: employees only.
        $ids = collect($this->getJson('/api/users?staff=1')->assertOk()->json())->pluck('id');
        $this->assertTrue($ids->contains($paralegal->id));
        $this->assertFalse($ids->contains($portal->id));
        $this->assertFalse($ids->contains($inventor->id));
    }
}
