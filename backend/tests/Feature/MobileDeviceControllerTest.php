<?php

namespace Tests\Feature;

use App\Models\MobileDeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileDeviceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_user_can_register_push_token(): void
    {
        $user = User::factory()->create([
            'role' => 'associate',
            'status' => 'Active',
        ]);

        Sanctum::actingAs($user, ['mobile']);

        $this->postJson('/api/mobile/push-tokens', [
            'push_token' => 'ExponentPushToken[test-1]',
            'platform' => 'android',
            'device_name' => 'Pixel 9',
            'app_version' => '1.0.0',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('mobile_device_tokens', [
            'user_id' => $user->id,
            'push_token' => 'ExponentPushToken[test-1]',
            'platform' => 'android',
            'device_name' => 'Pixel 9',
            'app_version' => '1.0.0',
        ]);
    }

    public function test_registering_existing_push_token_reassigns_it_to_latest_user(): void
    {
        $firstUser = User::factory()->create([
            'role' => 'associate',
            'status' => 'Active',
        ]);
        $secondUser = User::factory()->create([
            'role' => 'manager',
            'status' => 'Active',
        ]);

        MobileDeviceToken::create([
            'user_id' => $firstUser->id,
            'push_token' => 'ExponentPushToken[test-2]',
            'platform' => 'android',
            'device_name' => 'Old device',
            'app_version' => '0.9.0',
            'last_seen_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($secondUser, ['mobile']);

        $this->postJson('/api/mobile/push-tokens', [
            'push_token' => 'ExponentPushToken[test-2]',
            'platform' => 'ios',
            'device_name' => 'iPhone 16',
            'app_version' => '1.1.0',
        ])->assertOk();

        $this->assertDatabaseHas('mobile_device_tokens', [
            'user_id' => $secondUser->id,
            'push_token' => 'ExponentPushToken[test-2]',
            'platform' => 'ios',
            'device_name' => 'iPhone 16',
            'app_version' => '1.1.0',
        ]);
        $this->assertDatabaseCount('mobile_device_tokens', 1);
    }

    public function test_mobile_user_can_unregister_own_push_token(): void
    {
        $user = User::factory()->create([
            'role' => 'associate',
            'status' => 'Active',
        ]);

        $token = MobileDeviceToken::create([
            'user_id' => $user->id,
            'push_token' => 'ExponentPushToken[test-3]',
            'platform' => 'android',
            'device_name' => 'Pixel 9',
            'app_version' => '1.0.0',
            'last_seen_at' => now(),
        ]);

        Sanctum::actingAs($user, ['mobile']);

        $this->deleteJson('/api/mobile/push-tokens', [
            'push_token' => $token->push_token,
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('mobile_device_tokens', [
            'id' => $token->id,
        ]);
    }
}
