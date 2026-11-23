<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** "Registered Patent Agent" on the HRMS employee form feeds Settings → IPO Forms and Form 1. */
class EmployeePatentAgentTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    private User $login;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hr = $this->user('hr', 'hr@firm.test', 'HR');
        $this->login = $this->user('partner', 'arjun@firm.test', 'Arjun Mehta');
        $this->employee = $this->employeeFor($this->login, '9000000001');
    }

    private function user(string $role, string $email, string $name): User
    {
        return User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password'), 'role' => $role, 'status' => 'Active']);
    }

    private function employeeFor(?User $user, ?string $mobile = null, string $name = 'Arjun Mehta'): Employee
    {
        return Employee::create([
            'user_id'           => $user?->id,
            'employee_code'     => 'EMP-'.rand(10000, 99999),
            'full_name'         => $user?->name ?? $name,
            'work_email'        => $user?->email ?? strtolower(str_replace(' ', '.', $name)).'@firm.test',
            'mobile'            => $mobile,
            'employment_status' => 'Active',
            'date_of_joining'   => now()->subYear(),
        ]);
    }

    public function test_marking_an_employee_as_patent_agent_feeds_settings_and_form1(): void
    {
        Sanctum::actingAs($this->hr);

        $this->putJson("/api/hrms/employees/{$this->employee->id}", ['is_patent_agent' => true, 'patent_agent_no' => ' in/pa-1234 '])
            ->assertOk()->assertJsonPath('user.patent_agent_no', 'IN/PA-1234');
        $this->assertSame('IN/PA-1234', $this->login->fresh()->patent_agent_no);
        $this->assertDatabaseHas('audit_logs', ['action' => 'patent_agent_updated', 'subject_id' => $this->employee->id]);

        // Settings → IPO Forms lists them, with the mobile from the employee record.
        Sanctum::actingAs($this->login);
        $this->getJson('/api/settings/ipo')->assertOk()
            ->assertJsonCount(1, 'agents')
            ->assertJsonPath('agents.0.name', 'Arjun Mehta')
            ->assertJsonPath('agents.0.mobile', '9000000001');

        // Form 1 offers them as an agent.
        $client = Client::create(['client_code' => '397M', 'client_type' => 'organization', 'legal_name' => 'Acme', 'company_name' => 'Acme', 'nationality' => 'India', 'status' => 'Active']);
        $project = Project::create(['project_code' => '397M001INPAT', 'docket_number' => '397M001INPAT', 'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'Gripper', 'patent_office_code' => 'IN', 'status' => 'Open']);
        $this->getJson("/api/projects/{$project->id}/ipo-forms/form1")->assertOk()
            ->assertJsonPath('agent_options.0.patent_agent_no', 'IN/PA-1234')
            ->assertJsonPath('agent_options.0.mobile', '9000000001');
    }

    public function test_unticking_clears_the_agent_number(): void
    {
        $this->login->update(['patent_agent_no' => 'IN/PA-1234']);
        Sanctum::actingAs($this->hr);

        $this->putJson("/api/hrms/employees/{$this->employee->id}", ['is_patent_agent' => false, 'patent_agent_no' => null])->assertOk();
        $this->assertNull($this->login->fresh()->patent_agent_no);
    }

    public function test_agent_needs_a_number_a_unique_number_and_a_login(): void
    {
        Sanctum::actingAs($this->hr);

        $this->putJson("/api/hrms/employees/{$this->employee->id}", ['is_patent_agent' => true, 'patent_agent_no' => ''])->assertStatus(422);

        $other = $this->user('manager', 'priya@firm.test', 'Priya Nair');
        $other->update(['patent_agent_no' => 'IN/PA-5555']);
        $this->putJson("/api/hrms/employees/{$this->employee->id}", ['is_patent_agent' => true, 'patent_agent_no' => 'IN/PA-5555'])->assertStatus(422);
        $this->assertNull($this->login->fresh()->patent_agent_no);

        $noLogin = $this->employeeFor(null, null, 'No Login');
        $this->putJson("/api/hrms/employees/{$noLogin->id}", ['is_patent_agent' => true, 'patent_agent_no' => 'IN/PA-7777'])->assertStatus(422);
    }

    public function test_roles_that_cannot_edit_employees_cannot_mark_agents(): void
    {
        Sanctum::actingAs($this->user('paralegal', 'para@firm.test', 'Para'));

        $this->putJson("/api/hrms/employees/{$this->employee->id}", ['is_patent_agent' => true, 'patent_agent_no' => 'IN/PA-1234'])->assertForbidden();
        $this->assertNull($this->login->fresh()->patent_agent_no);
    }

    private function signatureFile(string $name = 'sign.png'): UploadedFile
    {
        // A real 1×1 PNG, so the image and mimetype rules see genuine image bytes.
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
    }

    public function test_hr_uploads_views_replaces_and_removes_an_agent_signature(): void
    {
        Storage::fake('local');
        $this->login->update(['patent_agent_no' => 'IN/PA-1234']);
        Sanctum::actingAs($this->hr);
        $url = "/api/hrms/employees/{$this->employee->id}/agent-signature";

        $this->get($url)->assertNotFound();
        $this->post($url, ['signature' => $this->signatureFile()], ['Accept' => 'application/json'])->assertCreated();
        $first = $this->login->fresh()->patent_agent_signature_path;
        $this->assertNotNull($first);
        Storage::disk('local')->assertExists($first);
        $this->assertDatabaseHas('audit_logs', ['action' => 'patent_agent_signature_updated', 'subject_id' => $this->employee->id]);
        $this->get($url)->assertOk();

        // The stored path never leaks through the employee API.
        $this->getJson('/api/hrms/employees')->assertOk()->assertDontSee('patent-agent-signatures');

        // Settings → IPO Forms shows it as uploaded.
        Sanctum::actingAs($this->login);
        $this->getJson('/api/settings/ipo')->assertOk()->assertJsonPath('agents.0.has_signature', true);

        // Replacing deletes the old file.
        Sanctum::actingAs($this->hr);
        $this->post($url, ['signature' => $this->signatureFile('new.png')], ['Accept' => 'application/json'])->assertCreated();
        Storage::disk('local')->assertMissing($first);

        $this->deleteJson($url)->assertOk();
        $this->assertNull($this->login->fresh()->patent_agent_signature_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'patent_agent_signature_removed', 'subject_id' => $this->employee->id]);
    }

    public function test_signature_must_be_png_or_jpg_of_2mb_or_less(): void
    {
        Storage::fake('local');
        $this->login->update(['patent_agent_no' => 'IN/PA-1234']);
        Sanctum::actingAs($this->hr);
        $url = "/api/hrms/employees/{$this->employee->id}/agent-signature";

        $this->post($url, ['signature' => UploadedFile::fake()->create('sign.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post($url, ['signature' => UploadedFile::fake()->create('sign.png', 2049, 'image/png')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertNull($this->login->fresh()->patent_agent_signature_path);
    }

    public function test_signature_needs_a_saved_patent_agent_and_an_employee_editor(): void
    {
        Storage::fake('local');
        $url = "/api/hrms/employees/{$this->employee->id}/agent-signature";

        Sanctum::actingAs($this->hr);
        $this->post($url, ['signature' => $this->signatureFile()], ['Accept' => 'application/json'])->assertStatus(422);

        $this->login->update(['patent_agent_no' => 'IN/PA-1234']);
        Sanctum::actingAs($this->user('paralegal', 'para@firm.test', 'Para'));
        $this->post($url, ['signature' => $this->signatureFile()], ['Accept' => 'application/json'])->assertForbidden();
        $this->get($url)->assertForbidden();
        $this->deleteJson($url)->assertForbidden();
    }

    public function test_unticking_the_agent_removes_the_signature(): void
    {
        Storage::fake('local');
        $this->login->update(['patent_agent_no' => 'IN/PA-1234']);
        Sanctum::actingAs($this->hr);
        $this->post("/api/hrms/employees/{$this->employee->id}/agent-signature", ['signature' => $this->signatureFile()], ['Accept' => 'application/json'])->assertCreated();
        $path = $this->login->fresh()->patent_agent_signature_path;

        $this->putJson("/api/hrms/employees/{$this->employee->id}", ['is_patent_agent' => false, 'patent_agent_no' => null])->assertOk();
        $this->assertNull($this->login->fresh()->patent_agent_signature_path);
        Storage::disk('local')->assertMissing($path);
    }
}
