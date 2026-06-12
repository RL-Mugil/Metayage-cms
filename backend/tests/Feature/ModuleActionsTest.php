<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ComplianceItem;
use App\Models\Integration;
use App\Models\JobPosting;
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
        $this->getJson('/api/compliance')->assertOk()->assertJsonCount(0);
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
        $titles = array_column($response, 'title');
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
}
