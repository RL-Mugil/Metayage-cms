<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Support\SignaturePin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The signature PIN: on the employee record, or on the login itself when it has none (a shared Accounts login). */
class SignaturePinTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_login_without_an_employee_record_sets_and_uses_its_own_pin(): void
    {
        $accounts = User::create(['name' => 'Accounts @ Metayage', 'email' => 'accounts@pin.test', 'password' => bcrypt('password'), 'role' => 'finance', 'status' => 'Active']);
        $this->assertFalse(SignaturePin::isSet($accounts));          // no default PIN for a shared login

        Sanctum::actingAs($accounts);
        $this->putJson('/api/settings/internal-signature-pin', ['pin' => '4821'])->assertOk();
        $accounts->refresh();
        $this->assertTrue(SignaturePin::verify($accounts, '4821'));
        $this->assertFalse(SignaturePin::verify($accounts, '0000'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'reset_internal_signature_pin', 'subject_type' => 'User', 'subject_id' => $accounts->id]);
        $this->assertArrayNotHasKey('internal_signature_pin_hash', $accounts->toArray());   // never sent to the browser
    }

    public function test_an_employee_keeps_the_pin_on_the_employee_record(): void
    {
        $user = User::create(['name' => 'Priya', 'email' => 'priya@pin.test', 'password' => bcrypt('password'), 'role' => 'finance', 'status' => 'Active']);
        $employee = Employee::create(['user_id' => $user->id, 'employee_code' => 'EMP-PIN-0001', 'full_name' => 'Priya', 'work_email' => 'priya@pin.test',
            'employment_status' => 'Active', 'date_of_joining' => now()->subYear()]);
        $this->assertTrue(SignaturePin::verify($user, '0000'));       // employees start with 0000

        Sanctum::actingAs($user);
        $this->putJson('/api/settings/internal-signature-pin', ['pin' => '1357'])->assertOk();
        $this->assertTrue(SignaturePin::verify($user, '1357'));
        $this->assertNull($user->fresh()->internal_signature_pin_hash);   // stored on the employee, not the login
        $this->assertDatabaseHas('audit_logs', ['action' => 'reset_internal_signature_pin', 'subject_type' => 'Employee', 'subject_id' => $employee->id]);
    }
}
