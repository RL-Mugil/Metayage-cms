<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Integration;
use App\Models\Project;
use App\Models\User;
use App\Models\ZohoInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * None of these controller actions ever call Zoho live — everything is served from the
 * local `zoho_invoices` mirror, so no Http::fake is needed here at all (that only
 * matters for SyncZohoBooksTest, which exercises the actual sync command).
 */
class ZohoBooksControllerTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'finance'): User
    {
        static $seq = 0;
        $seq++;
        return User::create([
            'name'     => ucfirst($role) . " User {$seq}",
            'email'    => $role . $seq . '@test.local',
            'password' => bcrypt('password'),
            'role'     => $role,
            'status'   => 'Active',
        ]);
    }

    private function makeClient(array $override = []): Client
    {
        return Client::create(array_merge([
            'client_code'  => '807M',
            'client_type'  => 'organization',
            'legal_name'   => 'Blinkcharging Software Solutions India Private Limited',
            'company_name' => 'Blinkcharging Software Solutions India Private Limited',
            'nationality'  => 'India',
            'status'       => 'Active',
        ], $override));
    }

    private function configureZoho(): void
    {
        Integration::create([
            'slug' => 'zoho', 'name' => 'Zoho Books', 'description' => 'Accounting',
            'category' => 'Finance', 'initials' => 'ZB', 'color' => 'bg-red-600',
            'connected' => true,
            'config' => [
                'client_id'       => 'test-client-id',
                'client_secret'   => encrypt('test-secret'),
                'refresh_token'   => encrypt('test-refresh'),
                'organization_id' => '924754718',
                'region'          => 'in',
                'api_key'         => encrypt('test-refresh'),
            ],
        ]);
    }

    public function test_unauthenticated_user_cannot_access(): void
    {
        $client = $this->makeClient();
        $this->getJson("/api/integrations/zoho/clients/{$client->id}/summary")->assertUnauthorized();
    }

    public function test_returns_422_when_not_configured(): void
    {
        Sanctum::actingAs($this->user());
        $client = $this->makeClient();

        $this->getJson("/api/integrations/zoho/clients/{$client->id}/summary")->assertStatus(422);
    }

    public function test_client_summary_reads_invoices_from_local_mirror(): void
    {
        $this->configureZoho();
        Sanctum::actingAs($this->user());

        $client  = $this->makeClient();
        $project = Project::create([
            'project_code' => '807M004INFFP', 'client_id' => $client->id,
            'project_type' => 'Patent', 'project_name' => 'Test Invention',
            'docket_number' => '807M004INFFP',
        ]);

        ZohoInvoice::create([
            'zoho_id' => 'zid-1', 'zoho_type' => 'invoice', 'client_id' => $client->id, 'project_id' => $project->id,
            'number' => '807M004INFFP/1', 'status' => 'overdue', 'total' => 8000, 'balance' => 8000,
            'currency' => 'INR', 'txn_date' => '2026-07-30', 'match_source' => 'uin', 'synced_at' => now(),
        ]);
        // A paid invoice shouldn't count toward outstanding balance.
        ZohoInvoice::create([
            'zoho_id' => 'zid-2', 'zoho_type' => 'invoice', 'client_id' => $client->id, 'project_id' => $project->id,
            'number' => '807M004INFFP', 'status' => 'paid', 'total' => 5000, 'balance' => 0,
            'currency' => 'INR', 'txn_date' => '2026-06-01', 'match_source' => 'docket', 'synced_at' => now(),
        ]);

        $response = $this->getJson("/api/integrations/zoho/clients/{$client->id}/summary")
            ->assertOk()
            ->json();

        $this->assertCount(2, $response['invoices']);
        $this->assertSame(8000.0, $response['outstanding_balance']);
        $overdue = collect($response['invoices'])->firstWhere('number', '807M004INFFP/1');
        $this->assertSame('807M004INFFP', $overdue['case']['docket_number']);
        $this->assertSame('uin', $overdue['case']['source']);
    }

    public function test_project_summary_scopes_to_one_case(): void
    {
        $this->configureZoho();
        Sanctum::actingAs($this->user());

        $client   = $this->makeClient();
        $projectA = Project::create(['project_code' => '807M004INFFP', 'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'Case A', 'docket_number' => '807M004INFFP']);
        $projectB = Project::create(['project_code' => '807M005INFFP', 'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'Case B', 'docket_number' => '807M005INFFP']);

        ZohoInvoice::create(['zoho_id' => 'a1', 'zoho_type' => 'invoice', 'client_id' => $client->id, 'project_id' => $projectA->id, 'number' => '807M004INFFP', 'status' => 'paid', 'total' => 1000, 'currency' => 'INR']);
        ZohoInvoice::create(['zoho_id' => 'b1', 'zoho_type' => 'invoice', 'client_id' => $client->id, 'project_id' => $projectB->id, 'number' => '807M005INFFP', 'status' => 'paid', 'total' => 2000, 'currency' => 'INR']);

        $response = $this->getJson("/api/integrations/zoho/projects/{$projectA->id}/summary")
            ->assertOk()
            ->json();

        $this->assertCount(1, $response['invoices']);
        $this->assertSame('807M004INFFP', $response['invoices'][0]['number']);
    }

    public function test_match_batch_reads_local_mirror_only(): void
    {
        $this->configureZoho();
        Sanctum::actingAs($this->user());

        $client = $this->makeClient();
        ZohoInvoice::create(['zoho_id' => 'z1', 'zoho_type' => 'invoice', 'client_id' => $client->id, 'number' => '807M004INFFP/1', 'status' => 'overdue', 'total' => 8000, 'balance' => 8000, 'currency' => 'INR']);

        $response = $this->postJson('/api/integrations/zoho/match', ['uins' => ['807m004inffp/1', 'UNKNOWN']])
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('807M004INFFP/1', $response);
        $this->assertSame('overdue', $response['807M004INFFP/1']['status']);
        $this->assertArrayNotHasKey('UNKNOWN', $response);
    }

    public function test_index_lists_all_synced_records_across_cases(): void
    {
        $this->configureZoho();
        Sanctum::actingAs($this->user());

        $clientA = $this->makeClient();
        $clientB = $this->makeClient(['client_code' => '601M', 'company_name' => 'KS Knitfabs']);
        ZohoInvoice::create(['zoho_id' => 'a1', 'zoho_type' => 'invoice', 'client_id' => $clientA->id, 'number' => '807M004INFFP', 'status' => 'paid', 'total' => 1000, 'currency' => 'INR']);
        ZohoInvoice::create(['zoho_id' => 'b1', 'zoho_type' => 'quote', 'client_id' => $clientB->id, 'number' => '601M001IN27F', 'status' => 'sent', 'total' => 500, 'currency' => 'INR']);

        $response = $this->getJson('/api/integrations/zoho/all')->assertOk()->json();
        $this->assertSame(2, $response['total']);

        $response = $this->getJson('/api/integrations/zoho/all?type=quote')->assertOk()->json();
        $this->assertSame(1, $response['total']);
        $this->assertSame('KS Knitfabs', $response['data'][0]['client']);
    }

    public function test_client_role_cannot_view_another_clients_summary(): void
    {
        $this->configureZoho();

        $owner = $this->makeClient();
        $other = $this->makeClient(['client_code' => '601M']);

        $portalUser = User::create([
            'name' => 'Portal User', 'email' => 'portal1@test.local',
            'password' => bcrypt('password'), 'role' => 'client', 'status' => 'Active',
        ]);
        $owner->update(['portal_user_id' => $portalUser->id]);

        Sanctum::actingAs($portalUser);

        $this->getJson("/api/integrations/zoho/clients/{$other->id}/summary")->assertForbidden();
    }
}
