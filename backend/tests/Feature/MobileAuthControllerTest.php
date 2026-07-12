<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_login_returns_bearer_token_for_active_user(): void
    {
        $user = User::factory()->create([
            'email' => 'mobile@test.local',
            'password' => 'password',
            'role' => 'manager',
            'status' => 'Active',
        ]);

        $response = $this->postJson('/api/mobile/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Pixel 9 Pro',
        ])->assertOk();

        $response->assertJsonStructure([
            'token',
            'token_type',
            'user' => ['id', 'name', 'email', 'role', 'status', 'permissions'],
        ]);

        $this->assertSame('Bearer', $response->json('token_type'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_mobile_login_rejects_inactive_user(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@test.local',
            'password' => 'password',
            'role' => 'associate',
            'status' => 'Inactive',
        ]);

        $this->postJson('/api/mobile/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'QA Device',
        ])->assertStatus(422);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_mobile_me_returns_authenticated_user_payload(): void
    {
        $user = User::factory()->create([
            'role' => 'partner',
            'status' => 'Active',
        ]);

        Sanctum::actingAs($user, ['mobile']);

        $this->getJson('/api/mobile/me')
            ->assertOk()
            ->assertJson([
                'id' => $user->id,
                'email' => $user->email,
                'role' => 'partner',
                'status' => 'Active',
            ]);
    }

    public function test_mobile_logout_revokes_current_token(): void
    {
        $user = User::factory()->create([
            'role' => 'associate',
            'status' => 'Active',
        ]);
        $token = $user->createToken('Android Test', ['mobile']);

        $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson('/api/mobile/auth/logout')
            ->assertOk()
            ->assertJson(['message' => 'Logged out']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
