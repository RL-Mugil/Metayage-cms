<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientDesignation;
use App\Models\Project;
use App\Models\User;
use App\Support\ClientAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Client portal designations with access checkboxes, client reports and the client's own audit log. */
class ClientAccessTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Client $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Anusha', 'email' => 'anusha@acc.test', 'password' => bcrypt('password'), 'role' => 'client_admin', 'status' => 'Active']);
        $this->client = Client::create(['client_code' => '950M', 'client_type' => 'organization', 'legal_name' => 'Kappa Labs', 'company_name' => 'Kappa Labs',
            'nationality' => 'India', 'status' => 'Active', 'portal_enabled' => true, 'portal_user_id' => $this->admin->id]);
        $this->other = Client::create(['client_code' => '951M', 'client_type' => 'organization', 'legal_name' => 'Lambda Inc', 'company_name' => 'Lambda Inc',
            'nationality' => 'India', 'status' => 'Active']);
        foreach ([[$this->client, '950M001INPAT'], [$this->other, '951M001INPAT']] as [$c, $docket]) {
            Project::create(['client_id' => $c->id, 'project_name' => 'Case '.$docket, 'project_code' => 'PRJ-'.$docket, 'docket_number' => $docket,
                'project_type' => 'Patent', 'service_code' => 'PAT', 'patent_office_code' => 'IN', 'status' => 'In Progress', 'hard_deadline' => now()->addDays(20)->toDateString()]);
        }
    }

    /** Ready-made designations (the migration makes them on a real database). */
    private function presets(): void
    {
        foreach (ClientAccess::PRESETS as $name => $permissions) {
            ClientDesignation::firstOrCreate(['client_id' => null, 'name' => $name], ['permissions' => $permissions, 'is_inventor' => $name === 'Inventor']);
        }
    }

    public function test_access_falls_back_to_the_old_role_and_the_boxes_gate_pages_and_apis(): void
    {
        $this->assertTrue(ClientAccess::can($this->admin, 'manage_users'));   // client_admin = Manager
        $finance = User::create(['name' => 'Accounts', 'email' => 'ap@kappa.test', 'password' => bcrypt('password'), 'role' => 'client_finance', 'status' => 'Active']);
        $this->client->contacts()->create(['name' => 'Accounts', 'email' => 'ap@kappa.test']);
        $this->assertTrue(ClientAccess::can($finance, 'billing'));
        $this->assertFalse(ClientAccess::can($finance, 'cases'));

        $this->actingAs($finance);
        $this->get('/projects')->assertRedirect('/');                    // no "Cases" box
        $this->get('/financial')->assertOk();
        Sanctum::actingAs($finance);
        $this->getJson('/api/my-portal/users')->assertForbidden();        // no "Manage users" box
    }

    public function test_a_client_admin_creates_a_designation_and_a_user_on_it(): void
    {
        $this->presets();
        Sanctum::actingAs($this->admin);
        $legal = $this->postJson('/api/my-portal/designations', ['name' => 'Legal Head', 'permissions' => ['cases', 'documents', 'reports']])->assertCreated()->json();
        $this->postJson('/api/my-portal/designations', ['name' => 'Manager', 'permissions' => ['cases']])->assertStatus(422);   // ready-made name

        $this->postJson('/api/my-portal/users', ['name' => 'Ravi', 'email' => 'ravi@kappa.test', 'password' => 'Secret#123A', 'designation_id' => $legal['id']])->assertCreated();
        $ravi = User::where('email', 'ravi@kappa.test')->firstOrFail();
        $this->assertSame('client', $ravi->role);
        $this->assertSame(['cases', 'documents', 'reports'], ClientAccess::permissions($ravi));
        $this->assertDatabaseHas('audit_logs', ['action' => 'portal_user_added', 'subject_id' => $this->client->id]);

        Sanctum::actingAs($ravi);
        $this->getJson('/api/my-billing')->assertForbidden();              // no billing box
        $this->getJson('/api/approvals')->assertForbidden();               // no approvals box

        // The admin gives Ravi the CFO designation: billing opens, cases close.
        Sanctum::actingAs($this->admin);
        $cfo = ClientDesignation::whereNull('client_id')->where('name', 'CFO')->value('id');
        $this->putJson("/api/my-portal/users/{$ravi->id}", ['designation_id' => $cfo])->assertOk();
        $this->assertSame('client_finance', $ravi->fresh()->role);
        $this->putJson("/api/my-portal/users/{$this->admin->id}", ['designation_id' => $cfo])->assertStatus(422);   // not your own access
    }

    public function test_a_client_report_is_only_about_their_own_client(): void
    {
        Sanctum::actingAs($this->admin);
        $rows = $this->getJson('/api/reports/data?type=ip-deadline')->assertOk()->json('rows');
        $codes = collect($rows)->pluck('client_code')->filter()->unique()->values()->all();
        $this->assertSame(['950M'], $codes);
        // Asking for another client's code still gives their own.
        $rows = $this->getJson('/api/reports/data?type=ip-deadline&client_code=951M')->assertOk()->json('rows');
        $this->assertNotContains('951M', collect($rows)->pluck('client_code')->all());
        $this->getJson('/api/reports/data?type=productivity')->assertForbidden();   // staff-only report
    }

    public function test_the_audit_log_shows_only_their_own_users(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@firm.test', 'password' => bcrypt('password'), 'role' => 'partner', 'status' => 'Active']);
        AuditLog::create(['user_id' => $this->admin->id, 'action' => 'client_approve_quote', 'subject_type' => 'PatentInvoiceIn', 'subject_id' => 1, 'metadata' => ['invoice_uin' => '950M001/Q1']]);
        AuditLog::create(['user_id' => $staff->id, 'action' => 'update_settings', 'subject_type' => 'User', 'subject_id' => 1]);
        Sanctum::actingAs($this->admin);
        $rows = $this->getJson('/api/my-portal/audit')->assertOk()->json('data');
        $this->assertSame(['Approved a quote'], array_column($rows, 'action'));
        $this->assertSame('950M001/Q1', $rows[0]['what']);
    }
}
