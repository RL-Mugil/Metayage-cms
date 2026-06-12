<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AIControllerTest extends TestCase
{
    use RefreshDatabase;

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

    // ──── Authorization ────
    public function test_unauthenticated_user_cannot_query_ai(): void
    {
        $this->postJson('/api/ai/query', ['query' => 'Show overdue matters'])
            ->assertUnauthorized();
    }

    public function test_all_authenticated_roles_can_access_ai_endpoint(): void
    {
        foreach (['super_admin', 'partner', 'manager', 'hr', 'associate', 'client'] as $role) {
            $user = $this->user($role);
            Sanctum::actingAs($user);
            // Endpoint should be accessible
            $response = $this->postJson('/api/ai/query', ['query' => 'Test query']);
            // May be 200 or 500 depending on Groq API availability
            $this->assertIn($response->getStatusCode(), [200, 500]);
        }
    }

    // ──── Input Validation ────
    public function test_empty_query_returns_422(): void
    {
        $user = $this->user('associate');
        Sanctum::actingAs($user);
        $this->postJson('/api/ai/query', ['query' => ''])->assertStatus(422);
        $this->postJson('/api/ai/query', [])->assertStatus(422);
    }

    public function test_query_too_long_rejected(): void
    {
        $user = $this->user('associate');
        Sanctum::actingAs($user);
        $longQuery = str_repeat('x', 5001);
        $this->postJson('/api/ai/query', ['query' => $longQuery])->assertStatus(422);
    }

    // ──── Query Format Validation ────
    public function test_query_with_special_characters_accepted(): void
    {
        $user = $this->user('associate');
        Sanctum::actingAs($user);

        $query = "What's the status of matter #123-ABC & trademark™ cases?";
        $response = $this->postJson('/api/ai/query', ['query' => $query]);
        // Should pass input validation
        $this->assertNotEquals(422, $response->getStatusCode());
    }

    public function test_query_with_numeric_filters_accepted(): void
    {
        $user = $this->user('associate');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/ai/query', ['query' => 'Show invoices over 50000']);
        // Should not be rejected for validation
        $this->assertNotEquals(422, $response->getStatusCode());
    }

    public function test_multiple_sequential_queries_from_same_user(): void
    {
        $user = $this->user('associate');
        Sanctum::actingAs($user);

        $queries = [
            'What is a patent?',
            'How many clients do we have?',
            'Show overdue invoices',
        ];

        foreach ($queries as $q) {
            $response = $this->postJson('/api/ai/query', ['query' => $q]);
            // All should pass auth/validation, may fail on API
            $this->assertNotEquals(401, $response->getStatusCode());
        }
    }

    // ──── Role-Based Access ────
    public function test_client_role_can_query_ai(): void
    {
        $client = $this->user('client');
        Sanctum::actingAs($client);

        $response = $this->postJson('/api/ai/query', ['query' => 'Show my cases']);
        $this->assertNotEquals(403, $response->getStatusCode()); // Not forbidden
    }

    public function test_admin_can_query_ai(): void
    {
        $admin = $this->user('super_admin');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai/query', ['query' => 'How many projects total?']);
        $this->assertNotEquals(403, $response->getStatusCode());
    }

    // ──── SQL Safety (if AI were to return SQL) ────
    public function test_endpoint_validates_query_parameter(): void
    {
        $user = $this->user('associate');
        Sanctum::actingAs($user);

        // Missing query parameter
        $this->postJson('/api/ai/query', [])->assertStatus(422);

        // Query must be string
        $this->postJson('/api/ai/query', ['query' => ['array']])->assertStatus(422);
    }

    // ──── Edge Cases ────
    public function test_query_with_newlines_accepted(): void
    {
        $user = $this->user('associate');
        Sanctum::actingAs($user);

        $query = "Show me all cases\nwhere status is active\nand due date is past";
        $response = $this->postJson('/api/ai/query', ['query' => $query]);
        $this->assertNotEquals(422, $response->getStatusCode());
    }

    public function test_query_with_unicode_characters(): void
    {
        $user = $this->user('associate');
        Sanctum::actingAs($user);

        $query = "¿Qué es una patente? العلامة التجارية 商标";
        $response = $this->postJson('/api/ai/query', ['query' => $query]);
        $this->assertNotEquals(422, $response->getStatusCode());
    }
}
