<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ModulePagesTest extends TestCase
{
    use RefreshDatabase;

    /** Each of the 9 demo-shell module routes and the Inertia component it must render. */
    private const MODULES = [
        '/portal'            => 'Portal',
        '/bulk'              => 'Bulk',
        '/compliance'        => 'Compliance',
        '/integrations'      => 'Integrations',
        '/feedback'          => 'Feedback',
        '/reminders'         => 'Reminders',
        '/hrms/performance'  => 'HRMS/Performance',
        '/hrms/recruitment'  => 'HRMS/Recruitment',
        '/hrms/offboarding'  => 'HRMS/Offboarding',
    ];

    private function user(string $role = 'manager'): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role . '@test.local',
            'password' => bcrypt('password'),
            'role' => $role,
            'status' => 'Active',
        ]);
    }

    public function test_guests_are_redirected_to_login_for_every_module(): void
    {
        foreach (array_keys(self::MODULES) as $route) {
            $this->get($route)->assertRedirect('/login');
        }
    }

    public function test_authenticated_user_can_render_every_module(): void
    {
        $user = $this->user();

        foreach (self::MODULES as $route => $component) {
            $this->actingAs($user)
                ->get($route)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($component));
        }
    }

    public function test_compliance_route_is_named(): void
    {
        $this->assertEquals(url('/compliance'), route('compliance'));
    }
}
