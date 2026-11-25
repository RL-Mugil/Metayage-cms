<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Integration;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Claude connector: OAuth (DCR + PKCE + refresh) and the read-only MCP endpoint. */
class McpConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function user(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role).' '.$email, 'email' => $email, 'password' => bcrypt('x'), 'role' => $role, 'status' => 'Active']);
    }

    private function register(): string
    {
        return $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [self::REDIRECT]])->assertCreated()->json('client_id');
    }

    /** Runs the whole sign-in as $user and returns [access_token, refresh_token, client_id]. */
    private function connect(User $user): array
    {
        $clientId = $this->register();
        $verifier = str_repeat('v', 20).bin2hex(random_bytes(16));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $params = ['client_id' => $clientId, 'redirect_uri' => self::REDIRECT, 'response_type' => 'code',
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'xyz'];

        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->actingAs($user, 'web');
        $this->get('/oauth/authorize?'.http_build_query($params))->assertOk();
        $res = $this->post('/oauth/authorize', $params + ['decision' => 'approve']);
        $location = $res->headers->get('Location') ?? $res->headers->get('X-Inertia-Location');
        $this->assertStringStartsWith(self::REDIRECT.'?', (string) $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $q);
        $this->assertSame('xyz', $q['state']);

        $this->app['auth']->forgetGuards();
        $tokens = $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $q['code'], 'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier])->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'expires_in'])->json();

        // The code is single-use.
        $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $q['code'], 'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);

        return [$tokens['access_token'], $tokens['refresh_token'], $clientId];
    }

    private function rpc(?string $token, string $method, array $params = [], int $id = 1)
    {
        $this->app['auth']->forgetGuards();
        $headers = $token ? ['Authorization' => 'Bearer '.$token] : [];

        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], $headers);
    }

    public function test_metadata_and_unauthenticated_challenge(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
            ->assertJson(['code_challenge_methods_supported' => ['S256'], 'grant_types_supported' => ['authorization_code', 'refresh_token']]);
        $this->getJson('/.well-known/oauth-protected-resource')->assertOk()->assertJsonPath('scopes_supported.0', 'mcp:read');

        $res = $this->rpc(null, 'initialize')->assertStatus(401);
        $this->assertStringContainsString('resource_metadata=', (string) $res->headers->get('WWW-Authenticate'));

        $this->postJson('/oauth/register', ['redirect_uris' => ['https://evil.example/cb']])->assertStatus(400);
        $this->get('/oauth/authorize?client_id=x')->assertRedirect('/login');
    }

    public function test_full_flow_tools_are_read_only_and_scoped(): void
    {
        $admin = $this->user('super_admin', 'sa@mcp.test');
        $analyst = $this->user('associate', 'an@mcp.test');
        $client = Client::create(['client_code' => '951M', 'client_type' => 'organization', 'legal_name' => 'Mcp Co', 'company_name' => 'Mcp Co', 'nationality' => 'India', 'status' => 'Active']);
        Sanctum::actingAs($admin);
        foreach (['951M001INPRV', '951M002INPRV'] as $docket) {
            $this->postJson('/api/projects', ['record_mode' => 'existing', 'project_code' => $docket, 'docket_number' => $docket, 'client_id' => $client->id,
                'project_name' => "Case {$docket}", 'project_type' => 'Patent', 'patent_office_code' => 'IN', 'service_code' => 'PRV'])->assertCreated();
        }
        Project::where('docket_number', '951M001INPRV')->update(['assigned_manager_id' => $analyst->id]);

        [$access, $refresh, $clientId] = $this->connect($analyst);

        $this->rpc($access, 'initialize', ['protocolVersion' => '2025-06-18'])->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-06-18')->assertJsonPath('result.capabilities.tools.listChanged', false);
        $this->app['auth']->forgetGuards();
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], ['Authorization' => 'Bearer '.$access])->assertStatus(202);

        $tools = collect($this->rpc($access, 'tools/list')->assertOk()->json('result.tools'));
        $this->assertTrue($tools->contains('name', 'search_cases'));
        $this->assertTrue($tools->every(fn ($t) => $t['annotations']['readOnlyHint'] === true && $t['annotations']['destructiveHint'] === false));
        $this->assertFalse($tools->contains(fn ($t) => preg_match('/create|update|delete|edit|mark|send/i', $t['name'])));

        $result = $this->rpc($access, 'tools/call', ['name' => 'search_cases', 'arguments' => ['query' => '951M']])->assertOk()->json('result');
        $this->assertFalse($result['isError']);
        $this->assertSame(1, $result['structuredContent']['total']);
        $this->assertSame('951M001INPRV', $result['structuredContent']['cases'][0]['docket_number']);

        $other = Project::where('docket_number', '951M002INPRV')->first();
        $this->assertTrue($this->rpc($access, 'tools/call', ['name' => 'get_case', 'arguments' => ['case_id' => $other->id]])->json('result.isError'));
        $this->rpc($access, 'tools/call', ['name' => 'delete_case', 'arguments' => []])->assertJsonPath('error.code', -32602);
        $this->rpc($access, 'resources/list')->assertJsonPath('error.code', -32601);

        // The connector token can't reach the portal API (which has write routes).
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/projects', ['Authorization' => 'Bearer '.$access])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->putJson("/api/projects/{$other->id}", ['project_name' => 'x'], ['Authorization' => 'Bearer '.$access])->assertForbidden();

        // Refresh rotates; the old refresh token stops working.
        $this->app['auth']->forgetGuards();
        $new = $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $clientId])->assertOk()->json();
        $this->app['auth']->forgetGuards();
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $refresh])->assertStatus(400);
        $this->rpc($access, 'ping')->assertStatus(401);           // old access token removed on rotation
        $this->rpc($new['access_token'], 'ping')->assertOk();

        // Connected apps → Disconnect revokes everything.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($analyst);
        $this->getJson('/api/oauth/connections')->assertOk()->assertJsonCount(1);
        $this->deleteJson("/api/oauth/connections/{$clientId}")->assertOk();
        $this->rpc($new['access_token'], 'ping')->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $new['refresh_token']])->assertStatus(400);
    }

    public function test_wrong_verifier_client_roles_and_kill_switch(): void
    {
        $partner = $this->user('partner', 'p@mcp.test');
        $clientId = $this->register();
        $verifier = str_repeat('a', 50);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $params = ['client_id' => $clientId, 'redirect_uri' => self::REDIRECT, 'response_type' => 'code', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256'];
        $this->actingAs($partner);
        $loc = $this->post('/oauth/authorize', $params + ['decision' => 'approve'])->headers->get('Location');
        parse_str(parse_url((string) $loc, PHP_URL_QUERY), $q);
        $this->app['auth']->forgetGuards();
        $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $q['code'], 'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT, 'code_verifier' => str_repeat('b', 50)])->assertStatus(400);

        // A normal (non-connector) token is not accepted on /mcp; client logins are refused.
        $normal = $partner->createToken('app')->plainTextToken;
        $this->rpc($normal, 'ping')->assertStatus(401);
        $clientUser = $this->user('client', 'cl@mcp.test');
        $this->rpc($clientUser->createToken('x', ['mcp:read'])->plainTextToken, 'ping')->assertForbidden();

        // System Admin turns the connector off.
        $mcpToken = $partner->createToken('Claude connector', ['mcp:read'])->plainTextToken;
        $this->rpc($mcpToken, 'ping')->assertOk();
        Integration::where('slug', 'claude_mcp')->update(['connected' => false]);
        $this->rpc($mcpToken, 'ping')->assertStatus(503);
    }
}
