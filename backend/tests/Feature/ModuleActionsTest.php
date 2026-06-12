<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ComplianceItem;
use App\Models\Employee;
use App\Models\Integration;
use App\Models\JobPosting;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\OffboardingCase;
use App\Models\PerformanceReview;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModuleActionsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role) . ' User',
            'email' => $role . '@test.local',
            'password' => bcrypt('password'),
            'role' => $role,
            'status' => 'Active',
        ]);
    }

    private function complianceItem(): ComplianceItem
    {
        return ComplianceItem::create([
            'matter' => 'US1234567 — Test Patent',
            'type' => 'Patent',
            'jurisdiction' => 'USPTO',
            'deadline' => now()->addDays(20)->toDateString(),
            'action_required' => 'Maintenance fee',
            'assignee' => 'Someone',
            'status' => 'Open',
        ]);
    }

    // ── Seeder ────────────────────────────────────────────────────────────────

    public function test_demo_modules_seeder_is_idempotent(): void
    {
        $this->user('super_admin');
        $this->seed(\Database\Seeders\DemoModulesSeeder::class);
        $this->seed(\Database\Seeders\DemoModulesSeeder::class); // second run must not duplicate

        $this->assertEquals(15, ComplianceItem::count());
        $this->assertEquals(8, Integration::count());
        $this->assertEquals(7, Reminder::count());
        $this->assertEquals(6, PerformanceReview::count());
        $this->assertEquals(4, JobPosting::count());
        $this->assertEquals(5, OffboardingCase::count());
    }

    // ── Compliance ────────────────────────────────────────────────────────────

    public function test_client_role_cannot_access_compliance(): void
    {
        Sanctum::actingAs($this->user('client'));
        $this->getJson('/api/compliance')->assertForbidden();
    }

    public function test_compliance_index_derives_alert_level(): void
    {
        $this->complianceItem(); // 20 days out → Critical
        Sanctum::actingAs($this->user('manager'));

        $this->getJson('/api/compliance')
            ->assertOk()
            ->assertJsonFragment(['status' => 'Critical']);
    }

    public function test_compliance_resolve_removes_item_from_index(): void
    {
        $item = $this->complianceItem();
        Sanctum::actingAs($this->user('manager'));

        $this->putJson("/api/compliance/{$item->id}", ['resolved' => true])->assertOk();
        $this->assertEquals('Resolved', $item->fresh()->status);
        $this->getJson('/api/compliance')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_compliance_note_and_assignee_persist(): void
    {
        $item = $this->complianceItem();
        Sanctum::actingAs($this->user('manager'));

        $this->putJson("/api/compliance/{$item->id}", ['assignee' => 'Priya Sharma', 'note' => 'Called client'])->assertOk();
        $fresh = $item->fresh();
        $this->assertEquals('Priya Sharma', $fresh->assignee);
        $this->assertEquals('Called client', $fresh->notes[0]['text']);
    }

    public function test_compliance_remind_creates_reminder_idempotently(): void
    {
        $item = $this->complianceItem();
        Sanctum::actingAs($this->user('manager'));

        $this->postJson("/api/compliance/{$item->id}/remind")->assertOk();
        $this->postJson("/api/compliance/{$item->id}/remind")->assertOk();
        $this->assertEquals(1, Reminder::where('source', "compliance:{$item->id}")->count());
    }

    // ── Reminders ─────────────────────────────────────────────────────────────

    public function test_reminder_crud_and_scoping(): void
    {
        $me = $this->user('manager');
        Sanctum::actingAs($me);

        $this->postJson('/api/reminders', [
            'title' => 'File response', 'category' => 'Deadline',
            'due_date' => now()->addDays(2)->toDateString(), 'scope' => 'self',
        ])->assertCreated();

        $other = $this->user('associate');
        Reminder::create([
            'user_id' => $other->id, 'title' => 'Private to other', 'category' => 'Meeting',
            'due_date' => now()->toDateString(), 'scope' => 'self',
        ]);
        Reminder::create([
            'user_id' => $other->id, 'title' => 'Team-wide', 'category' => 'Renewal',
            'due_date' => now()->toDateString(), 'scope' => 'team',
        ]);

        $response = $this->getJson('/api/reminders')->assertOk()->json();
        $titles = array_column($response['data'], 'title');
        $this->assertContains('File response', $titles);
        $this->assertContains('Team-wide', $titles);
        $this->assertNotContains('Private to other', $titles);
    }

    public function test_reminder_toggle_persists(): void
    {
        $me = $this->user('manager');
        Sanctum::actingAs($me);
        $reminder = Reminder::create([
            'user_id' => $me->id, 'title' => 'Toggle me', 'category' => 'Deadline',
            'due_date' => now()->toDateString(), 'scope' => 'self',
        ]);

        $this->putJson("/api/reminders/{$reminder->id}", ['completed' => true])->assertOk();
        $this->assertTrue($reminder->fresh()->completed);
    }

    // ── Feedback ──────────────────────────────────────────────────────────────

    public function test_feedback_request_creates_notification(): void
    {
        Sanctum::actingAs($this->user('manager'));

        $this->postJson('/api/feedback/request', ['client' => 'Acme', 'subject' => 'Q2 survey'])->assertCreated();
        $this->assertDatabaseHas('ip_notifications', ['type' => 'feedback_request']);
    }

    // ── Performance ───────────────────────────────────────────────────────────

    public function test_associate_cannot_access_performance(): void
    {
        Sanctum::actingAs($this->user('associate'));
        $this->getJson('/api/performance')->assertForbidden();
    }

    public function test_submit_review_computes_rating_and_locks(): void
    {
        $review = PerformanceReview::create([
            'employee' => 'Kavya Nair', 'reviewer' => 'Priya Sharma', 'period' => 'Q2 2026',
            'status' => 'In Progress',
        ]);
        Sanctum::actingAs($this->user('hr'));

        $scores = ['technical' => 5, 'communication' => 4, 'teamwork' => 4, 'leadership' => 3, 'initiative' => 4];
        $this->postJson("/api/performance/reviews/{$review->id}/submit", ['scores' => $scores, 'comments' => 'Solid quarter'])
            ->assertOk()
            ->assertJson(['rating' => 4.0]);

        $fresh = $review->fresh();
        $this->assertEquals('Completed', $fresh->status);
        $this->assertEquals($scores, $fresh->scores);

        // A completed review cannot be re-submitted.
        $this->postJson("/api/performance/reviews/{$review->id}/submit", ['scores' => $scores])->assertStatus(422);
    }

    // ── Recruitment ───────────────────────────────────────────────────────────

    public function test_hr_can_publish_and_close_job_but_manager_cannot(): void
    {
        Sanctum::actingAs($this->user('hr'));
        $jobId = $this->postJson('/api/recruitment/jobs', ['title' => 'IP Analyst', 'dept' => 'Legal'])
            ->assertCreated()
            ->json('job.id');

        $this->putJson("/api/recruitment/jobs/{$jobId}", ['status' => 'Closed'])->assertOk();
        $this->assertEquals('Closed', JobPosting::find($jobId)->status);

        Sanctum::actingAs($this->user('manager'));
        $this->postJson('/api/recruitment/jobs', ['title' => 'Nope'])->assertForbidden();
    }

    // ── Offboarding ───────────────────────────────────────────────────────────

    public function test_offboarding_checklist_completion_flow(): void
    {
        Sanctum::actingAs($this->user('hr'));

        $id = $this->postJson('/api/offboarding', [
            'employee' => 'Test Person', 'dept' => 'Ops', 'last_day' => '2026-07-31', 'exit_type' => 'Resignation',
        ])->assertCreated()->json('id');

        // Partial checklist → In Progress
        $partial = array_merge([true, true], array_fill(0, 6, false));
        $this->putJson("/api/offboarding/{$id}/checklist", ['checklist' => $partial])
            ->assertOk()->assertJson(['status' => 'In Progress']);

        // Full checklist → Completed
        $this->putJson("/api/offboarding/{$id}/checklist", ['checklist' => array_fill(0, 8, true)])
            ->assertOk()->assertJson(['status' => 'Completed']);
        $this->assertNotNull(OffboardingCase::find($id)->completed_label);
    }

    // ── Integrations ──────────────────────────────────────────────────────────

    public function test_integration_toggle_persists_and_hides_config(): void
    {
        Integration::create([
            'slug' => 'slack', 'name' => 'Slack', 'description' => 'Chat', 'category' => 'Comms',
            'initials' => 'SL', 'color' => 'bg-purple-600', 'connected' => false,
        ]);
        Sanctum::actingAs($this->user('manager'));

        $this->postJson('/api/integrations/slack/toggle')->assertOk()->assertJson(['connected' => true]);
        $this->postJson('/api/integrations/slack/config', ['api_key' => 'sk-secret'])->assertOk();

        $list = $this->getJson('/api/integrations')->assertOk()->json();
        $this->assertTrue($list[0]['hasKey']);
        $this->assertArrayNotHasKey('config', $list[0]); // key material never leaves the server

        Sanctum::actingAs($this->user('associate'));
        $this->getJson('/api/integrations')->assertForbidden();
    }

    // ── Portal ────────────────────────────────────────────────────────────────

    private function client(string $name, string $code): Client
    {
        return Client::create(['company_name' => $name, 'client_code' => $code, 'status' => 'Active']);
    }

    public function test_portal_toggle_and_invite_all(): void
    {
        $client = $this->client('Acme Corp', 'C01M');
        Sanctum::actingAs($this->user('manager'));

        $this->postJson("/api/portal/clients/{$client->id}/toggle")->assertOk()->assertJson(['portal_enabled' => true]);
        $this->assertTrue((bool) $client->fresh()->portal_enabled);

        $this->postJson("/api/portal/clients/{$client->id}/toggle")->assertOk()->assertJson(['portal_enabled' => false]);

        $this->postJson('/api/portal/invite-all')->assertOk()->assertJson(['invited' => 1]);
        $this->assertNotNull($client->fresh()->portal_invited_at);
        $this->assertDatabaseHas('ip_notifications', ['type' => 'portal_invite']);
    }

    // ── Bulk ──────────────────────────────────────────────────────────────────

    public function test_bulk_change_status_on_clients(): void
    {
        $a = $this->client('A', 'C02M');
        $b = $this->client('B', 'C03M');
        Sanctum::actingAs($this->user('manager'));

        $this->postJson('/api/bulk/execute', [
            'entity' => 'clients', 'ids' => [$a->id, $b->id], 'action' => 'change_status', 'status' => 'On Hold',
        ])->assertOk()->assertJson(['affected' => 2]);

        $this->assertEquals('On Hold', $a->fresh()->status);
        $this->assertEquals('On Hold', $b->fresh()->status);
    }

    public function test_bulk_rejects_unsafe_input(): void
    {
        $client = $this->client('A', 'C04M');
        Sanctum::actingAs($this->user('manager'));

        // Invoices are not bulk-mutable
        $this->postJson('/api/bulk/execute', [
            'entity' => 'invoices', 'ids' => [1], 'action' => 'change_status', 'status' => 'Paid',
        ])->assertStatus(422);

        // Non-whitelisted status
        $this->postJson('/api/bulk/execute', [
            'entity' => 'clients', 'ids' => [$client->id], 'action' => 'change_status', 'status' => 'Hacked',
        ])->assertStatus(422);

        // Associates cannot bulk-operate
        Sanctum::actingAs($this->user('associate'));
        $this->postJson('/api/bulk/execute', [
            'entity' => 'clients', 'ids' => [$client->id], 'action' => 'archive',
        ])->assertForbidden();
    }

    // ── Leave Management ────────────────────────────────────────────

    private function employee(string $name, string $code, User $user): \App\Models\Employee
    {
        return \App\Models\Employee::create([
            'user_id' => $user->id,
            'full_name' => $name,
            'employee_code' => $code,
            'work_email' => strtolower($name) . '@test.local',
            'date_of_joining' => now()->toDateString(),
            'employment_type' => 'Full-time',
            'employment_status' => 'Active',
        ]);
    }

    public function test_employee_can_apply_leave(): void
    {
        $user = $this->user('associate');
        $emp = $this->employee('Kavya Nair', 'EMP001', $user);
        Sanctum::actingAs($user);

        $this->postJson('/api/leaves', [
            'leave_type' => 'Annual',
            'from_date' => now()->addDays(5)->toDateString(),
            'to_date' => now()->addDays(7)->toDateString(),
            'reason' => 'Vacation',
        ])->assertCreated()->assertJsonFragment(['status' => 'Pending']);

        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $emp->id,
            'leave_type' => 'Annual',
            'total_days' => 3,
        ]);
    }

    public function test_leave_balance_is_deducted_on_approval(): void
    {
        $user = $this->user('hr');
        $emp_user = $this->user('associate');
        $emp = $this->employee('Arjun Menon', 'EMP002', $emp_user);

        // Create leave balance for current year
        \App\Models\LeaveBalance::create([
            'employee_id' => $emp->id,
            'year' => date('Y'),
            'earned_leave' => 10,
            'casual_leave' => 5,
            'sick_leave' => 3,
        ]);

        // Employee applies for 3 days of annual leave
        $leave = \App\Models\LeaveRequest::create([
            'employee_id' => $emp->id,
            'leave_type' => 'Annual',
            'from_date' => now()->addDays(5)->toDateString(),
            'to_date' => now()->addDays(7)->toDateString(),
            'total_days' => 3,
            'status' => 'Pending',
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/approvals/resolve', [
            'type' => 'Leave',
            'id' => $leave->id,
            'action' => 'Approved',
        ])->assertOk();

        // Balance should be deducted from earned_leave
        $balance = \App\Models\LeaveBalance::where('employee_id', $emp->id)->first();
        $this->assertEquals(7, $balance->earned_leave); // 10 - 3
        $this->assertEquals(0, $balance->lop_days);
    }

    public function test_shortfall_days_accrue_as_lop(): void
    {
        $user = $this->user('hr');
        $emp_user = $this->user('associate');
        $emp = $this->employee('Priya Sharma', 'EMP003', $emp_user);

        // Create balance with only 2 days of annual leave
        \App\Models\LeaveBalance::create([
            'employee_id' => $emp->id,
            'year' => date('Y'),
            'earned_leave' => 2,
            'casual_leave' => 5,
            'sick_leave' => 3,
        ]);

        // Apply for 5 days (only 2 available)
        $leave = \App\Models\LeaveRequest::create([
            'employee_id' => $emp->id,
            'leave_type' => 'Annual',
            'from_date' => now()->addDays(10)->toDateString(),
            'to_date' => now()->addDays(14)->toDateString(),
            'total_days' => 5,
            'status' => 'Pending',
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/approvals/resolve', [
            'type' => 'Leave',
            'id' => $leave->id,
            'action' => 'Approved',
        ])->assertOk();

        // 2 deducted from balance, 3 days become LOP
        $balance = \App\Models\LeaveBalance::where('employee_id', $emp->id)->first();
        $this->assertEquals(0, $balance->earned_leave);
        $this->assertEquals(3, $balance->lop_days);
    }

    public function test_rejected_leave_does_not_deduct_balance(): void
    {
        $user = $this->user('hr');
        $emp_user = $this->user('associate');
        $emp = $this->employee('Rohan Patel', 'EMP004', $emp_user);

        \App\Models\LeaveBalance::create([
            'employee_id' => $emp->id,
            'year' => date('Y'),
            'earned_leave' => 10,
            'casual_leave' => 5,
            'sick_leave' => 3,
        ]);

        $leave = \App\Models\LeaveRequest::create([
            'employee_id' => $emp->id,
            'leave_type' => 'Annual',
            'from_date' => now()->addDays(5)->toDateString(),
            'to_date' => now()->addDays(7)->toDateString(),
            'total_days' => 3,
            'status' => 'Pending',
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/approvals/resolve', [
            'type' => 'Leave',
            'id' => $leave->id,
            'action' => 'Rejected',
        ])->assertOk();

        // Balance unchanged on rejection
        $balance = \App\Models\LeaveBalance::where('employee_id', $emp->id)->first();
        $this->assertEquals(10, $balance->earned_leave);
    }

    // ── Data Integrity ─────────────────────────────────────────────────────────

    public function test_client_with_invoices_cannot_be_deleted(): void
    {
        $client = $this->client('Acme Corp', 'C01M');
        \App\Models\Invoice::create([
            'client_id' => $client->id,
            'invoice_code' => 'INV-001',
            'invoice_type' => 'Standard',
            'subtotal' => 50000,
            'total_amount' => 50000,
            'balance_due' => 50000,
            'status' => 'Draft',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        Sanctum::actingAs($this->user('partner'));
        $response = $this->deleteJson("/api/clients/{$client->id}");
        $response->assertStatus(422);
        $this->assertStringContainsString('Cannot delete client', $response->json('message'));

        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }

    public function test_client_without_invoices_can_be_deleted(): void
    {
        $empty = $this->client('Empty Corp', 'C06M');
        Sanctum::actingAs($this->user('super_admin'));

        $response = $this->deleteJson("/api/clients/{$empty->id}");
        $response->assertOk();

        // Client uses SoftDeletes, so check deleted_at is set
        $this->assertNotNull(\App\Models\Client::withTrashed()->find($empty->id)->deleted_at);
    }

    public function test_salary_is_encrypted_in_database(): void
    {
        $user = $this->user('hr');
        $emp = $this->employee('Test Employee', 'EMP005', $user);

        Sanctum::actingAs($user);
        $this->putJson('/api/hrms/employees/' . $emp->id, [
            'salary' => 500000,
        ])->assertOk();

        // Verify salary is encrypted at rest
        $raw = \DB::table('employees')->where('id', $emp->id)->value('salary');
        $this->assertNotNull($raw);
        // Encrypted values start with eyJ (base64 encoded JSON)
        $this->assertStringStartsWith('eyJ', $raw);
    }
}
