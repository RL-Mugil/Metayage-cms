<?php

namespace Tests\Feature;

use App\Models\Firm;
use App\Models\User;
use App\Support\FirmContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FirmContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_is_attached_when_only_one_active_firm_exists(): void
    {
        $firm = Firm::query()->where('slug', 'legacy-firm')->sole();
        $user = $this->user('legacy-user@example.test');

        $this->assertDatabaseHas('firm_user', [
            'firm_id' => $firm->id,
            'user_id' => $user->id,
            'role' => 'associate',
            'status' => 'Active',
        ]);
        $this->assertSame($firm->id, $user->fresh()->current_firm_id);

        Sanctum::actingAs($user);
        $this->getJson('/api/me')->assertOk();
        $this->assertFalse(app(FirmContext::class)->hasFirm());
    }

    public function test_user_without_membership_is_denied_when_multiple_firms_exist(): void
    {
        $user = $this->user('unassigned-user@example.test');
        $user->firmMemberships()->delete();
        $user->forceFill(['current_firm_id' => null])->saveQuietly();
        $this->firm('second-firm');

        Sanctum::actingAs($user);
        $this->getJson('/api/me')
            ->assertForbidden()
            ->assertJson(['message' => 'No active firm membership is available for this account.']);

        $this->assertDatabaseMissing('firm_user', ['user_id' => $user->id]);
        $this->assertNull($user->fresh()->current_firm_id);
    }

    public function test_explicit_current_firm_membership_is_preserved(): void
    {
        $user = $this->user('selected-user@example.test');
        $firm = $this->firm('selected-firm');
        $user->firmMemberships()->delete();
        $user->firms()->attach($firm->id, [
            'role' => 'manager',
            'status' => 'Active',
            'is_default' => true,
            'joined_at' => now(),
        ]);
        $user->forceFill(['current_firm_id' => $firm->id])->saveQuietly();

        Sanctum::actingAs($user);
        $this->getJson('/api/me')->assertOk();

        $this->assertSame($firm->id, $user->fresh()->current_firm_id);
        $this->assertCount(1, $user->fresh()->firms);
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Tenant Test User',
            'email' => $email,
            'password' => 'password',
            'role' => 'associate',
            'status' => 'Active',
        ]);
    }

    private function firm(string $slug): Firm
    {
        return Firm::query()->create([
            'name' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'status' => 'Active',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency' => 'USD',
        ]);
    }
}
