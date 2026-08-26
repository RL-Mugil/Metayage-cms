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
 * Role-specific dashboards from the 397/269 pilot plan: client_finance gets a
 * billing-only payload, client/client_admin get the interactive action feed
 * (renewals first — see ActionItemService::clientActionFeed).
 */
class ClientDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function client(): Client
    {
        return Client::create([
            'client_code' => '397M', 'client_type' => 'organization',
            'legal_name' => 'Client 397', 'company_name' => 'Client 397',
            'nationality' => 'India', 'status' => 'Active', 'portal_enabled' => true,
        ]);
    }

    private function portalUser(Client $client, string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role), 'email' => $role . '@397.test',
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'Active',
        ]);
        $client->update(['portal_user_id' => $user->id]);
        return $user;
    }

    public function test_client_finance_gets_billing_only_metrics(): void
    {
        $client = $this->client();
        $user = $this->portalUser($client, 'client_finance');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/dashboard/metrics')->assertOk()->json();

        $this->assertArrayHasKey('zoho_outstanding', $response['metrics']);
        $this->assertArrayHasKey('ledger_balance', $response['metrics']);
        $this->assertArrayHasKey('pending_invoices', $response['metrics']);
        $this->assertArrayHasKey('action_items', $response['metrics']);
        $this->assertArrayHasKey('renewal_items', $response['metrics']);
        $this->assertArrayNotHasKey('active_matters', $response['metrics']);
    }

    public function test_client_admin_gets_action_feed_with_renewals_first(): void
    {
        $client = $this->client();
        $user = $this->portalUser($client, 'client_admin');
        Sanctum::actingAs($user);

        $fer = Project::create([
            'project_code' => '397M001INFER', 'docket_number' => '397M001INFER',
            'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'FER case',
            'status' => 'Open', 'patent_office_code' => 'IN', 'service_code' => 'FER',
        ]);
        ProjectStage::create(['project_id' => $fer->id, 'stage_name' => 'Examination Report Received', 'status' => 'In Progress', 'sequence_order' => 0]);

        $renewal = Project::create([
            'project_code' => '397M002INREN', 'docket_number' => '397M002INREN',
            'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'Renewal case',
            'status' => 'Open', 'patent_office_code' => 'IN', 'service_code' => 'RNF',
        ]);
        ProjectStage::create(['project_id' => $renewal->id, 'stage_name' => 'Renewal Fee Paid', 'status' => 'In Progress', 'sequence_order' => 0]);

        $response = $this->getJson('/api/dashboard/metrics')->assertOk()->json();
        $items = $response['metrics']['action_items'];

        $this->assertCount(2, $items);
        $this->assertTrue($items[0]['is_renewal']);
        $this->assertSame('397M002INREN', $items[0]['docket_number']);
        $this->assertSame('Renewal due', $items[0]['pending_action']);
    }

    public function test_client_finance_cannot_reach_case_pages(): void
    {
        $client = $this->client();
        $user = $this->portalUser($client, 'client_finance');
        Sanctum::actingAs($user);

        $this->get('/projects')->assertRedirect('/');
        $this->get('/financial')->assertOk();
    }
}
