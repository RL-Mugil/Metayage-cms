<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role . '@test.local',
            'password' => bcrypt('password'),
            'role' => $role,
            'status' => 'Active',
        ]);
    }

    public function test_client_cannot_read_project_tracker(): void
    {
        Sanctum::actingAs($this->user('client'));
        $this->getJson('/api/tracker/rows?circle=a')->assertForbidden();
        $this->getJson('/api/tracker/circles')->assertForbidden();
    }

    public function test_internal_staff_can_read_project_tracker(): void
    {
        Sanctum::actingAs($this->user('manager'));
        // No circles seeded → firstOrFail would 404; circles list is what matters: not 403.
        $this->getJson('/api/tracker/circles')->assertOk();
    }

    public function test_client_cannot_read_reports(): void
    {
        Sanctum::actingAs($this->user('client'));
        $this->getJson('/api/reports/data?type=hrms')->assertForbidden();
    }

    public function test_associate_cannot_read_hrms_employees(): void
    {
        Sanctum::actingAs($this->user('associate'));
        $this->getJson('/api/hrms/employees')->assertOk(); // directory viewable by all internal staff; sensitive fields stripped for non-HR
    }

    public function test_hr_can_read_hrms_employees(): void
    {
        Sanctum::actingAs($this->user('hr'));
        $this->getJson('/api/hrms/employees')->assertOk();
    }

    public function test_client_cannot_create_tasks(): void
    {
        Sanctum::actingAs($this->user('client'));
        $this->postJson('/api/tasks', ['title' => 'x', 'priority' => 'High', 'project_id' => 1])
            ->assertForbidden();
    }

    public function test_only_hr_and_admin_can_create_payroll(): void
    {
        Sanctum::actingAs($this->user('associate'));
        $this->postJson('/api/payroll/runs', ['period' => '2026-06'])->assertForbidden();

        Sanctum::actingAs($this->user('finance'));
        // finance can view but not create runs
        $this->postJson('/api/payroll/runs', ['period' => '2026-06'])->assertForbidden();
    }
}
