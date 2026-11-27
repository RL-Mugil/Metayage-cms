<?php

namespace Tests\Feature;

use App\Ai\ToolRegistry;
use App\Models\Client;
use App\Models\PatentInvoiceIn;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Client logins: AI Assistant, Claude connector and API keys, read-only and about their own client only. */
class ClientAiApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Anusha', 'email' => 'anusha@ai.test', 'password' => bcrypt('password'), 'role' => 'client_admin', 'status' => 'Active']);
        $this->client = Client::create(['client_code' => '960M', 'client_type' => 'organization', 'legal_name' => 'Mu Labs', 'company_name' => 'Mu Labs',
            'nationality' => 'India', 'status' => 'Active', 'portal_enabled' => true, 'portal_user_id' => $this->admin->id]);
        $other = Client::create(['client_code' => '961M', 'client_type' => 'organization', 'legal_name' => 'Nu Inc', 'company_name' => 'Nu Inc', 'nationality' => 'India', 'status' => 'Active']);
        foreach ([[$this->client, '960M001INPAT'], [$other, '961M001INPAT']] as [$c, $docket]) {
            Project::create(['client_id' => $c->id, 'project_name' => 'Case '.$docket, 'project_code' => 'PRJ-'.$docket, 'docket_number' => $docket,
                'project_type' => 'Patent', 'service_code' => 'PAT', 'patent_office_code' => 'IN', 'status' => 'In Progress', 'hard_deadline' => now()->addDays(15)->toDateString()]);
        }
        PatentInvoiceIn::create(['type' => 'invoice', 'status' => 'Sent', 'source' => 'manual', 'client_id' => $this->client->id, 'invoice_uin' => '960M001/1',
            'invoice_date' => now()->subDays(40)->toDateString(), 'due_date' => now()->subDays(10)->toDateString(), 'invoice_amount' => 5900, 'total_bcy' => 5900,
            'balance_due' => 5900, 'exchange_rate' => 1, 'currency' => 'INR', 'payment_status' => 'Pending']);
    }

    public function test_a_client_gets_only_their_own_tools_and_data(): void
    {
        $tools = array_keys(app(ToolRegistry::class)->for($this->admin));
        sort($tools);
        $this->assertSame(['my_approvals', 'my_billing', 'my_cases', 'my_deadlines'], $tools);   // no staff tools

        $cases = app(ToolRegistry::class)->call($this->admin, 'my_cases', [], 'test');
        $this->assertSame(['960M001INPAT'], array_column($cases['cases'], 'docket_number'));
        $billing = app(ToolRegistry::class)->call($this->admin, 'my_billing', [], 'test');
        $this->assertEquals(5900, $billing['unpaid_invoices']);
        $this->assertSame(['error' => 'Unknown tool: search_clients'], app(ToolRegistry::class)->call($this->admin, 'search_clients', [], 'test'));

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/ai/providers')->assertOk();   // Manager has the AI box
    }

    public function test_an_api_key_reads_the_client_api_only(): void
    {
        Sanctum::actingAs($this->admin);
        $key = $this->postJson('/api/my-portal/api-keys', ['name' => 'Our ERP'])->assertCreated()->json('key');
        $this->assertCount(1, $this->getJson('/api/my-portal/api-keys')->json());

        $this->app['auth']->forgetGuards();
        $res = $this->getJson('/api/client-api/v1/cases', ['Authorization' => "Bearer {$key}"])->assertOk()->json();
        $this->assertSame(['960M001INPAT'], array_column($res['cases'], 'docket_number'));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/client-api/v1/deadlines?days=30', ['Authorization' => "Bearer {$key}"])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/projects', ['Authorization' => "Bearer {$key}"])->assertForbidden();   // the key opens nothing else
        $this->assertDatabaseHas('audit_logs', ['user_id' => $this->admin->id, 'action' => 'ai_tool_call']);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $this->admin->id, 'action' => 'api_key_created']);

        // A plain client login without the "Claude, API & integrations" box cannot make keys.
        $plain = User::create(['name' => 'Plain', 'email' => 'plain@ai.test', 'password' => bcrypt('password'), 'role' => 'client', 'status' => 'Active']);
        $this->client->contacts()->create(['name' => 'Plain', 'email' => 'plain@ai.test']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($plain);
        $this->postJson('/api/my-portal/api-keys', ['name' => 'x'])->assertForbidden();
        $this->postJson('/api/ai/query', ['query' => 'hi'])->assertForbidden();
    }

    public function test_a_client_admin_can_use_the_claude_connector_with_their_own_tools(): void
    {
        $token = $this->admin->createToken('Claude connector', ['mcp:read'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $list = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []], ['Authorization' => "Bearer {$token}"])->assertOk()->json('result.tools');
        $names = array_column($list, 'name');
        sort($names);
        $this->assertSame(['my_approvals', 'my_billing', 'my_cases', 'my_deadlines'], $names);
    }
}
