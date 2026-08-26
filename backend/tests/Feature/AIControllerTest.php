<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AIControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.groq.api_key', 'test-groq-key');
    }

    private function groqResponse(string $content = 'Here is a general answer about IP law.'): array
    {
        return [
            'choices' => [
                ['message' => ['content' => $content]],
            ],
        ];
    }

    private function user(string $role = 'associate'): User
    {
        return User::create([
            'name'     => ucfirst($role) . ' User',
            'email'    => $role . '@test.local',
            'password' => bcrypt('password'),
            'role'     => $role,
            'status'   => 'Active',
        ]);
    }

    private function fakeGroq(string $content = 'Here is a general answer about IP law.', int $status = 200): void
    {
        Http::fake(['api.groq.com/*' => Http::response($this->groqResponse($content), $status)]);
    }

    // ──── Authorization ────

    public function test_unauthenticated_user_cannot_query_ai(): void
    {
        // No HTTP call reaches Groq for unauthenticated requests.
        $this->postJson('/api/ai/query', ['query' => 'Show overdue matters'])
            ->assertUnauthorized();
    }

    public function test_all_authenticated_roles_get_200(): void
    {
        foreach (['super_admin', 'partner', 'manager', 'associate'] as $role) {
            Http::fake(['api.groq.com/*' => Http::response($this->groqResponse(), 200)]);

            Sanctum::actingAs($this->user($role));
            $this->postJson('/api/ai/query', ['query' => 'Test query'])
                ->assertOk()
                ->assertJsonStructure(['response', 'sql_query', 'results']);
        }
    }

    // ──── Input Validation ────

    public function test_empty_query_returns_422(): void
    {
        Sanctum::actingAs($this->user());
        $this->postJson('/api/ai/query', ['query' => ''])->assertStatus(422);
        $this->postJson('/api/ai/query', [])->assertStatus(422);
    }

    public function test_query_too_long_rejected(): void
    {
        Sanctum::actingAs($this->user());
        $this->postJson('/api/ai/query', ['query' => str_repeat('x', 1001)])
            ->assertStatus(422);
    }

    public function test_query_must_be_string(): void
    {
        Sanctum::actingAs($this->user());
        $this->postJson('/api/ai/query', ['query' => ['array']])->assertStatus(422);
    }

    // ──── Happy Path — Response Shape ────

    public function test_plain_text_response_has_correct_shape(): void
    {
        $this->fakeGroq();
        Sanctum::actingAs($this->user());
        $response = $this->postJson('/api/ai/query', ['query' => 'What is a patent?'])
            ->assertOk();

        $response->assertJsonStructure(['response', 'sql_query', 'results']);
        $this->assertNull($response->json('sql_query'));
        $this->assertIsArray($response->json('results'));
        $this->assertEmpty($response->json('results'));
    }

    public function test_sql_response_runs_query_and_returns_results(): void
    {
        $sqlContent = "Here are your projects.\n```sql\nSELECT id, project_name FROM projects LIMIT 5\n```";
        Http::fake(['api.groq.com/*' => Http::response($this->groqResponse($sqlContent), 200)]);

        Sanctum::actingAs($this->user('super_admin'));
        $response = $this->postJson('/api/ai/query', ['query' => 'List all projects'])
            ->assertOk();

        $this->assertNotNull($response->json('sql_query'));
        $this->assertStringContainsString('SELECT', strtoupper($response->json('sql_query')));
        $this->assertIsArray($response->json('results'));
    }

    // ──── SQL Safety ────

    public function test_ai_returning_write_sql_is_rejected(): void
    {
        $dangerous = "Sure!\n```sql\nDELETE FROM users WHERE 1=1\n```";
        Http::fake(['api.groq.com/*' => Http::response($this->groqResponse($dangerous), 200)]);

        Sanctum::actingAs($this->user('super_admin'));
        $this->postJson('/api/ai/query', ['query' => 'delete all users'])
            ->assertStatus(500); // guardSql throws → caught → 500
    }

    public function test_ai_returning_stacked_statements_is_rejected(): void
    {
        $stacked = "```sql\nSELECT 1; DROP TABLE users;\n```";
        Http::fake(['api.groq.com/*' => Http::response($this->groqResponse($stacked), 200)]);

        Sanctum::actingAs($this->user('super_admin'));
        $this->postJson('/api/ai/query', ['query' => 'do something bad'])
            ->assertStatus(500);
    }

    // ──── Groq API Failure ────

    public function test_groq_api_failure_returns_500(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['error' => 'rate limit'], 429)]);

        Sanctum::actingAs($this->user());
        $this->postJson('/api/ai/query', ['query' => 'How many clients?'])
            ->assertStatus(500)
            ->assertJson(['message' => 'AI service error. Please try again.']);
    }

    // ──── Edge Cases ────

    public function test_query_with_special_characters_accepted(): void
    {
        $this->fakeGroq();
        Sanctum::actingAs($this->user());
        $this->postJson('/api/ai/query', [
            'query' => "What's the status of matter #123-ABC & trademark™ cases?",
        ])->assertOk();
    }

    public function test_query_with_unicode_accepted(): void
    {
        $this->fakeGroq();
        Sanctum::actingAs($this->user());
        $this->postJson('/api/ai/query', [
            'query' => '¿Qué es una patente? العلامة التجارية 商标',
        ])->assertOk();
    }

    public function test_query_with_newlines_accepted(): void
    {
        $this->fakeGroq();
        Sanctum::actingAs($this->user());
        $this->postJson('/api/ai/query', [
            'query' => "Show cases\nwhere status is active\nand due date is past",
        ])->assertOk();
    }
}
