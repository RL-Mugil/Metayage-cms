<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Integration;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** AI Assistant page: Claude / Groq answer through the read-only portal tools, as the user. */
class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role).' '.$email, 'email' => $email, 'password' => bcrypt('x'), 'role' => $role, 'status' => 'Active']);
    }

    private function seedCases(User $analyst): void
    {
        $admin = $this->user('super_admin', 'sa@ai.test');
        $client = Client::create(['client_code' => '901M', 'client_type' => 'organization', 'legal_name' => 'Ai Co', 'company_name' => 'Ai Co', 'nationality' => 'India', 'status' => 'Active']);
        Sanctum::actingAs($admin);
        foreach (['901M001INPRV', '901M002INPRV'] as $docket) {
            $this->postJson('/api/projects', ['record_mode' => 'existing', 'project_code' => $docket, 'docket_number' => $docket, 'client_id' => $client->id,
                'project_name' => "Case {$docket}", 'project_type' => 'Patent', 'patent_office_code' => 'IN', 'service_code' => 'PRV'])->assertCreated();
        }
        Project::where('docket_number', '901M001INPRV')->update(['assigned_manager_id' => $analyst->id]);
    }

    private function saveKey(string $slug, string $key): void
    {
        $row = Integration::where('slug', $slug)->firstOrFail();
        $row->config = ['api_key' => encrypt($key)];
        $row->connected = true;
        $row->save();
    }

    public function test_claude_calls_a_tool_as_the_user_and_answers(): void
    {
        $analyst = $this->user('associate', 'an@ai.test');
        $this->seedCases($analyst);
        $this->saveKey('anthropic', 'sk-ant-test');

        Http::fakeSequence('api.anthropic.com/*')
            ->push(['stop_reason' => 'tool_use', 'content' => [
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'search_cases', 'input' => ['query' => '901M']],
            ]])
            ->push(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'You have 1 case: 901M001INPRV.']]]);

        Sanctum::actingAs($analyst);
        $this->postJson('/api/ai/query', ['query' => 'my cases?', 'provider' => 'claude'])
            ->assertOk()->assertJson(['response' => 'You have 1 case: 901M001INPRV.', 'tools_used' => ['search_cases'], 'provider' => 'claude']);

        // The tool result sent back to Claude holds only the analyst's own case.
        $recorded = Http::recorded()->map(fn ($pair) => $pair[0])->values();
        $second = $recorded[1]->data();
        $toolResult = json_decode($second['messages'][2]['content'][0]['content'], true);
        $this->assertSame(1, $toolResult['total']);
        $this->assertSame('901M001INPRV', $toolResult['cases'][0]['docket_number']);
        $this->assertSame('sk-ant-test', $recorded[0]->header('x-api-key')[0]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai_tool_call', 'user_id' => $analyst->id]);
    }

    public function test_groq_tool_loop_and_missing_key(): void
    {
        $director = $this->user('partner', 'd@ai.test');
        $this->seedCases($this->user('associate', 'an2@ai.test'));
        config()->set('services.groq.api_key', null);

        Sanctum::actingAs($director);
        $this->postJson('/api/ai/query', ['query' => 'hi', 'provider' => 'groq'])->assertStatus(422);
        $this->postJson('/api/ai/query', ['query' => 'hi', 'provider' => 'claude'])->assertStatus(422);
        $this->getJson('/api/ai/providers')->assertOk()->assertJson(['claude' => false, 'groq' => false, 'mcp_enabled' => true]);

        config()->set('services.groq.api_key', 'gsk-test');   // .env fallback still works
        Http::fakeSequence('api.groq.com/*')
            ->push(['choices' => [['message' => ['content' => null, 'tool_calls' => [
                ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'case_analytics', 'arguments' => '{"group_by":"status"}']],
            ]]]]])
            ->push(['choices' => [['message' => ['content' => 'Two open cases.']]]]);
        $this->postJson('/api/ai/query', ['query' => 'cases by status', 'provider' => 'groq', 'history' => [['role' => 'user', 'content' => 'earlier'], ['role' => 'assistant', 'content' => 'ok']]])
            ->assertOk()->assertJson(['response' => 'Two open cases.', 'tools_used' => ['case_analytics']]);
        $toolMsg = collect(Http::recorded()->last()[0]->data()['messages'])->firstWhere('role', 'tool');
        $this->assertSame(2, json_decode($toolMsg['content'], true)['totals']['cases']);
    }

    public function test_client_logins_cannot_use_the_assistant_and_upstream_errors_are_hidden(): void
    {
        $client = $this->user('client', 'c@ai.test');
        Sanctum::actingAs($client);
        $this->postJson('/api/ai/query', ['query' => 'hi'])->assertForbidden();

        $this->saveKey('anthropic', 'sk-ant-test');
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'secret detail']], 500)]);
        Sanctum::actingAs($this->user('partner', 'p@ai.test'));
        $res = $this->postJson('/api/ai/query', ['query' => 'hi', 'provider' => 'claude'])->assertStatus(502);
        $this->assertStringNotContainsString('secret', $res->getContent());
    }

    public function test_ai_keys_are_system_admin_only_and_never_returned(): void
    {
        Sanctum::actingAs($this->user('manager', 'pa@ai.test'));
        $this->postJson('/api/integrations/anthropic/config', ['api_key' => 'sk-ant-x'])->assertForbidden();
        $this->postJson('/api/integrations/claude_mcp/toggle')->assertForbidden();

        Sanctum::actingAs($this->user('super_admin', 'sa2@ai.test'));
        $this->postJson('/api/integrations/anthropic/config', ['api_key' => 'sk-ant-secret-value', 'model' => 'claude-haiku-5-5'])->assertOk();
        $list = $this->getJson('/api/integrations')->assertOk();
        $this->assertStringNotContainsString('sk-ant-secret-value', $list->getContent());
        $card = collect($list->json())->firstWhere('id', 'anthropic');
        $this->assertTrue($card['hasKey']);
        $this->assertTrue($card['connected']);
        $this->assertSame('claude-haiku-5-5', $card['model']);

        $this->postJson('/api/integrations/claude_mcp/toggle')->assertOk()->assertJson(['connected' => false]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'claude_connector_disabled']);
    }
}
