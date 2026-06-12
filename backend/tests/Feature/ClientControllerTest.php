<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientControllerTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'partner'): User
    {
        return User::create([
            'name'     => ucfirst($role) . ' User',
            'email'    => $role . '@test.local',
            'password' => bcrypt('password'),
            'role'     => $role,
            'status'   => 'Active',
        ]);
    }

    private function validClientData(array $override = []): array
    {
        static $counter = 0;
        $counter++;
        $legalName = 'Test Company Ltd.';
        return array_merge([
            'client_code'  => 'C' . str_pad($counter, 2, '0', STR_PAD_LEFT) . 'M',
            'legal_name'   => $legalName,
            'company_name' => $legalName,
            'client_type'  => 'organization',
            'entity_type'  => 'Corporation',
            'industry'     => 'Tech',
            'status'       => 'Active',
        ], $override);
    }

    // ──── Authorization ────
    public function test_unauthenticated_user_cannot_list_clients(): void
    {
        $this->getJson('/api/clients')->assertUnauthorized();
    }

    public function test_non_partner_cannot_create_client(): void
    {
        $user = $this->user('associate');
        Sanctum::actingAs($user);

        $this->postJson('/api/clients', $this->validClientData(['company_name' => 'Acme Corp']))
            ->assertForbidden();
    }

    public function test_partner_can_create_client(): void
    {
        $user = $this->user('partner');
        Sanctum::actingAs($user);

        $this->postJson('/api/clients', $this->validClientData([
            'legal_name' => 'Acme Corporation Ltd.',
        ]))->assertCreated()->assertJsonFragment(['legal_name' => 'Acme Corporation Ltd.']);
    }

    public function test_super_admin_can_create_client(): void
    {
        $user = $this->user('super_admin');
        Sanctum::actingAs($user);

        $this->postJson('/api/clients', $this->validClientData([
            'legal_name' => 'Beta Inc.',
            'industry'   => 'Pharma',
        ]))->assertCreated();
    }

    // ──── Input Validation ────
    public function test_create_client_missing_required_fields(): void
    {
        $user = $this->user('partner');
        Sanctum::actingAs($user);

        $this->postJson('/api/clients', [])->assertStatus(422);
        $this->postJson('/api/clients', ['legal_name' => 'Test'])->assertStatus(422);
        $this->postJson('/api/clients', $this->validClientData(['legal_name' => null]))->assertStatus(422);
    }

    public function test_create_client_with_optional_fields(): void
    {
        $user = $this->user('partner');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/clients', $this->validClientData([
            'company_name' => 'Test Corp Optional',
            'website'      => 'https://example.com',
        ]))->assertCreated();
        $this->assertNotNull($response->json()['id']);
    }

    public function test_client_code_must_be_unique(): void
    {
        $user = $this->user('partner');
        Sanctum::actingAs($user);

        Client::create($this->validClientData([
            'company_name' => 'First Corp',
            'client_code'  => 'FIRST-001',
        ]));

        $this->postJson('/api/clients', [
            'company_name' => 'Second Corp',
            'client_code'  => 'FIRST-001',
            'industry'     => 'Tech',
            'status'       => 'Active',
        ])->assertStatus(422);
    }

    // ──── List & Filtering ────
    public function test_list_clients_returns_paginated_results(): void
    {
        $user = $this->user('partner');
        Sanctum::actingAs($user);

        // Create 30 clients to ensure pagination
        for ($i = 1; $i <= 30; $i++) {
            Client::create($this->validClientData([
                'legal_name'  => "Client $i Ltd.",
                'client_code' => "CL-" . str_pad($i, 4, '0', STR_PAD_LEFT),
            ]));
        }

        $response = $this->getJson('/api/clients')->assertOk()->json();
        $this->assertIsArray($response['data']);
        $this->assertLessThanOrEqual(25, count($response['data'])); // Default per_page is 25
        $this->assertGreaterThan(1, $response['last_page']);
    }

    public function test_list_clients_search_by_company_name(): void
    {
        $user = $this->user('partner');
        Sanctum::actingAs($user);

        Client::create(['company_name' => 'Acme Corp', 'industry' => 'Tech', 'status' => 'Active']);
        Client::create(['company_name' => 'Beta Ltd', 'industry' => 'Pharma', 'status' => 'Active']);

        $response = $this->getJson('/api/clients?search=Acme')->assertOk()->json();
        $this->assertGreaterThan(0, count($response['data']));
        $this->assertEquals('Acme Corp', $response['data'][0]['company_name']);
    }

    // ──── Update & Delete ────
    public function test_partner_can_update_client(): void
    {
        $user = $this->user('partner');
        Sanctum::actingAs($user);

        $client = Client::create(array_merge($this->validClientData(), [
            'legal_name' => 'Original Name',
        ]));

        $this->putJson("/api/clients/{$client->id}", [
            'company_name' => 'Updated Name',
            'industry'     => 'Pharma',
        ])->assertOk()->assertJsonFragment(['company_name' => 'Updated Name']);
    }

    public function test_associate_cannot_update_client(): void
    {
        $associate = $this->user('associate');
        $partner = $this->user('partner');

        Sanctum::actingAs($partner);
        $client = Client::create(array_merge($this->validClientData(), [
            'legal_name' => 'Test Corp',
        ]));

        Sanctum::actingAs($associate);
        $this->putJson("/api/clients/{$client->id}", [
            'company_name' => 'Hacked Name',
        ])->assertForbidden();

        $this->assertEquals('Test Corp', $client->fresh()->company_name);
    }

    public function test_delete_client_with_active_projects_protected(): void
    {
        $user = $this->user('super_admin');
        Sanctum::actingAs($user);

        $client = Client::create(array_merge($this->validClientData(), [
            'legal_name' => 'Client with Projects',
        ]));

        // Create a related project (assume hasMany relationship exists)
        $client->projects()->create([
            'project_code' => 'PROJ-001',
            'project_name' => 'Test Project',
            'project_type' => 'Patent',
            'case_type'    => 'Patent',
            'status'       => 'Active',
        ]);

        // Try to delete - should fail or soft delete
        $response = $this->deleteJson("/api/clients/{$client->id}");
        $this->assertNotNull(Client::find($client->id)); // Client still exists
    }

    public function test_can_delete_client_with_no_projects(): void
    {
        $user = $this->user('super_admin');
        Sanctum::actingAs($user);

        $client = Client::create(array_merge($this->validClientData(), [
            'legal_name' => 'Standalone Client',
        ]));

        $this->deleteJson("/api/clients/{$client->id}")->assertOk();
        $this->assertNull(Client::find($client->id));
    }

    // ──── Contact Management ────
    public function test_add_contact_to_client(): void
    {
        $user = $this->user('partner');
        Sanctum::actingAs($user);

        $client = Client::create(array_merge($this->validClientData(), [
            'legal_name' => 'Test Corp',
        ]));

        $this->postJson("/api/clients/{$client->id}/contacts", [
            'name'      => 'John Doe',
            'email'     => 'john@test.com',
            'phone'     => '1234567890',
            'role_type' => 'Legal',
        ])->assertCreated()->assertJsonFragment(['name' => 'John Doe']);
    }

    public function test_contact_email_must_be_valid(): void
    {
        $user = $this->user('partner');
        Sanctum::actingAs($user);

        $client = Client::create(array_merge($this->validClientData(), [
            'legal_name' => 'Test Corp',
        ]));

        $this->postJson("/api/clients/{$client->id}/contacts", [
            'name'      => 'Jane Doe',
            'email'     => 'invalid-email',
            'phone'     => '9876543210',
        ])->assertStatus(422);
    }

    public function test_duplicate_contact_email_for_same_client_rejected(): void
    {
        $user = $this->user('partner');
        Sanctum::actingAs($user);

        $client = Client::create(array_merge($this->validClientData(), [
            'legal_name' => 'Test Corp',
        ]));

        $this->postJson("/api/clients/{$client->id}/contacts", [
            'name'      => 'John Doe',
            'email'     => 'john@test.com',
            'phone'     => '1234567890',
        ])->assertCreated();

        $this->postJson("/api/clients/{$client->id}/contacts", [
            'name'      => 'John Smith',
            'email'     => 'john@test.com',
            'phone'     => '9876543210',
        ])->assertStatus(422);
    }

    // ──── Edge Cases ────
    public function test_client_status_transitions(): void
    {
        $user = $this->user('partner');
        Sanctum::actingAs($user);

        $client = Client::create(array_merge($this->validClientData(), [
            'legal_name' => 'Test Corp',
        ]));

        // Active -> Inactive
        $this->putJson("/api/clients/{$client->id}", ['status' => 'Inactive'])->assertOk();
        $this->assertEquals('Inactive', $client->fresh()->status);

        // Inactive -> Active
        $this->putJson("/api/clients/{$client->id}", ['status' => 'Active'])->assertOk();
        $this->assertEquals('Active', $client->fresh()->status);
    }

    public function test_client_with_special_characters_in_name(): void
    {
        $user = $this->user('partner');
        Sanctum::actingAs($user);

        $name = "O'Reilly & Associates (Pvt.) Ltd.";
        $this->postJson('/api/clients', $this->validClientData([
            'legal_name' => $name,
        ]))->assertCreated()->assertJsonFragment(['legal_name' => $name]);
    }

    public function test_view_nonexistent_client_returns_404(): void
    {
        $user = $this->user('partner');
        Sanctum::actingAs($user);

        $this->getJson('/api/clients/99999')->assertNotFound();
    }
}
