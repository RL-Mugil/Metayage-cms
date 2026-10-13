<?php

namespace Tests\Feature;

use App\Models\SiteEnquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CpiAdminTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.'@test.local', 'password' => bcrypt('secret-pass-1'),
            'role' => $role, 'status' => 'Active',
        ]);
    }

    public function test_super_admin_can_sign_in_and_see_enquiries(): void
    {
        $this->user('super_admin');
        SiteEnquiry::create(['source' => 'canpatentit', 'name' => 'A B', 'email' => 'a@b.co', 'message' => 'hello there friend']);

        $token = $this->postJson('/api/cpi-admin/login', ['email' => 'super_admin@test.local', 'password' => 'secret-pass-1'])
            ->assertOk()->json('token');

        $h = ['Authorization' => 'Bearer '.$token];
        $this->getJson('/api/cpi-admin/enquiries', $h)->assertOk()->assertJsonCount(1);
        $this->getJson('/api/cpi-admin/overview', $h)->assertOk()->assertJsonPath('totals.all', 1);
    }

    public function test_non_admin_and_wrong_password_cannot_sign_in(): void
    {
        $this->user('partner');
        $this->user('super_admin');

        $this->postJson('/api/cpi-admin/login', ['email' => 'partner@test.local', 'password' => 'secret-pass-1'])->assertStatus(422);
        $this->postJson('/api/cpi-admin/login', ['email' => 'super_admin@test.local', 'password' => 'wrong'])->assertStatus(422);
    }

    public function test_dashboard_requires_a_token(): void
    {
        $this->getJson('/api/cpi-admin/enquiries')->assertUnauthorized();
    }

    public function test_enquiry_can_be_marked_handled(): void
    {
        $this->user('super_admin');
        $row = SiteEnquiry::create(['source' => 'canpatentit', 'name' => 'A B', 'email' => 'a@b.co', 'message' => 'hello there friend']);
        $token = $this->postJson('/api/cpi-admin/login', ['email' => 'super_admin@test.local', 'password' => 'secret-pass-1'])->json('token');

        $this->patchJson('/api/cpi-admin/enquiries/'.$row->id, ['status' => 'handled'], ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->assertSame('handled', $row->fresh()->status);
    }
}
