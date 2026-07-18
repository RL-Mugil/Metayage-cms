<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Client;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingsAttendancePortalTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $extra = []): User
    {
        return User::create(array_merge([
            'name'     => ucfirst($role) . ' User',
            'email'    => $role . '@test.local',
            'password' => bcrypt('password'),
            'role'     => $role,
            'status'   => 'Active',
        ], $extra));
    }

    private function employeeFor(User $user): Employee
    {
        return Employee::create([
            'user_id'            => $user->id,
            'employee_code'      => 'EMP-TEST-0001',
            'full_name'          => $user->name,
            'work_email'         => $user->email,
            'employment_status'  => 'Active',
            'date_of_joining'    => now()->toDateString(),
        ]);
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    public function test_settings_profile_timezone_and_language_persist(): void
    {
        $user = $this->user('hr');
        Sanctum::actingAs($user);

        $this->putJson('/api/settings/profile', [
            'name'     => 'HR User',
            'email'    => 'hr@test.local',
            'timezone' => 'America/New_York',
            'language' => 'Hindi',
        ])->assertOk();

        $fetched = $this->getJson('/api/settings')->assertOk()->json();
        $this->assertEquals('America/New_York', $fetched['profile']['timezone']);
        $this->assertEquals('Hindi', $fetched['profile']['language']);
    }

    public function test_settings_notification_preferences_persist_to_db(): void
    {
        $user = $this->user('associate');
        Sanctum::actingAs($user);

        $this->putJson('/api/settings/notifications', [
            'taskAssigned'    => false,
            'deadlineEmail'   => true,
            'paymentReceived' => false,
            'pushNotif'       => true,
            'weeklyDigest'    => false,
            'monthlyReport'   => true,
        ])->assertOk();

        $fetched = $this->getJson('/api/settings')->assertOk()->json();
        $this->assertFalse($fetched['notifications']['taskAssigned']);
        $this->assertTrue($fetched['notifications']['pushNotif']);
        $this->assertFalse($fetched['notifications']['weeklyDigest']);
    }

    public function test_settings_password_change_rejects_wrong_current_password(): void
    {
        $user = $this->user('associate');
        Sanctum::actingAs($user);

        $this->putJson('/api/settings/password', [
            'current_password'      => 'wrong-password',
            'password'              => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['current_password']);
    }

    public function test_settings_password_change_succeeds_with_correct_current(): void
    {
        $user = $this->user('associate');
        Sanctum::actingAs($user);

        $this->putJson('/api/settings/password', [
            'current_password'      => 'password',
            'password'              => 'NewSecure123!',
            'password_confirmation' => 'NewSecure123!',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertTrue(Hash::check('NewSecure123!', $user->fresh()->password));
    }

    public function test_settings_system_only_accessible_by_admin_and_partner(): void
    {
        $assoc = $this->user('associate');
        Sanctum::actingAs($assoc);

        $this->putJson('/api/settings/system', [
            'company' => 'Test Firm', 'currency' => 'USD',
            'fiscalMonth' => 'January', 'maxUploadMB' => '25',
        ])->assertForbidden();

        $admin = $this->user('super_admin');
        Sanctum::actingAs($admin);
        $this->putJson('/api/settings/system', [
            'company' => 'Test Firm', 'currency' => 'USD',
            'fiscalMonth' => 'January', 'maxUploadMB' => '25',
        ])->assertOk();
    }

    // ── Attendance ────────────────────────────────────────────────────────────

    public function test_clock_in_creates_attendance_record(): void
    {
        $user     = $this->user('associate');
        $employee = $this->employeeFor($user);
        Sanctum::actingAs($user);

        $this->postJson('/api/hrms/clock-in')->assertCreated();

        $log = Attendance::where('employee_id', $employee->id)->first();
        $this->assertNotNull($log);
        // AttendanceController stamps the date in IST; compare in the same zone
        // so the assertion is stable across the UTC/IST date boundary.
        $this->assertEquals(now('Asia/Kolkata')->toDateString(), $log->attendance_date->toDateString());
        $this->assertEquals('Present', $log->status);
        $this->assertNotNull($log->check_in);
    }

    public function test_double_clock_in_returns_400(): void
    {
        $user     = $this->user('associate');
        $employee = $this->employeeFor($user);
        Sanctum::actingAs($user);

        $this->postJson('/api/hrms/clock-in')->assertCreated();
        $this->postJson('/api/hrms/clock-in')->assertStatus(400)
             ->assertJsonFragment(['message' => 'You are already clocked in. Please clock out first.']);
    }

    public function test_clock_out_updates_check_out_and_duration(): void
    {
        $user     = $this->user('associate');
        $employee = $this->employeeFor($user);
        Sanctum::actingAs($user);

        $this->postJson('/api/hrms/clock-in')->assertCreated();
        $this->postJson('/api/hrms/clock-out')->assertOk();

        $log = Attendance::where('employee_id', $employee->id)->first();
        $this->assertNotNull($log->check_out);
        $this->assertGreaterThanOrEqual(0, $log->duration_minutes);
    }

    public function test_clock_in_without_employee_profile_returns_422(): void
    {
        $user = $this->user('super_admin');
        Sanctum::actingAs($user);

        $this->postJson('/api/hrms/clock-in')
             ->assertStatus(422)
             ->assertJsonFragment(['message' => 'No employee profile linked to your account. Contact HR.']);
    }

    // ── Portal ────────────────────────────────────────────────────────────────

    public function test_portal_clients_endpoint_returns_all_clients(): void
    {
        // /api/portal/clients lists portal-linked clients (portal_user_id set).
        $pa = $this->user('client', ['email' => 'portal-a@test.local']);
        $pb = $this->user('client', ['email' => 'portal-b@test.local']);
        Client::create(['company_name' => 'Alpha Corp', 'client_code' => 'ALPHA-001', 'portal_user_id' => $pa->id]);
        Client::create(['company_name' => 'Beta Ltd',  'client_code' => 'BETA-001', 'portal_user_id' => $pb->id]);

        $user = $this->user('partner');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/portal/clients')->assertOk()->json();
        $this->assertCount(2, $response);
    }

    public function test_admin_can_reset_any_users_password(): void
    {
        $target = $this->user('associate');
        $admin  = $this->user('super_admin');
        Sanctum::actingAs($admin);

        $this->putJson("/api/users/{$target->id}/reset-password", [
            'password' => 'Resetted123!',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertTrue(Hash::check('Resetted123!', $target->fresh()->password));
    }

    public function test_non_admin_cannot_reset_others_password(): void
    {
        $target = $this->user('associate');
        $other  = $this->user('hr');
        Sanctum::actingAs($other);

        $this->putJson("/api/users/{$target->id}/reset-password", [
            'password' => 'hacked123',
        ])->assertForbidden();
    }
}
