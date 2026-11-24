<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Bulk Operations run each item through the single-edit path, with the access model. */
class BulkOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Client::create(['client_code' => '601M', 'client_type' => 'organization', 'legal_name' => 'Bulk Co', 'company_name' => 'Bulk Co',
            'nationality' => 'India', 'status' => 'Active']);
    }

    private function user(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role).' '.$email, 'email' => $email, 'password' => bcrypt('x'), 'role' => $role, 'status' => 'Active']);
    }

    private function createCase(string $docket, array $extra = []): Project
    {
        $id = $this->postJson('/api/projects', [
            'record_mode' => 'existing', 'project_code' => $docket, 'docket_number' => $docket, 'client_id' => $this->client->id,
            'project_name' => "Case {$docket}", 'project_type' => 'Patent', 'patent_office_code' => 'IN', 'service_code' => 'PRV',
        ])->assertCreated()->json('id');
        $case = Project::findOrFail($id);
        $case->update($extra);

        return $case->fresh();
    }

    public function test_status_assign_and_delete_go_through_the_single_edit_rules(): void
    {
        $admin = $this->user('super_admin', 'sa@bulk.test');
        $analyst = $this->user('associate', 'an@bulk.test');
        Sanctum::actingAs($admin);
        $a = $this->createCase('601M001INPRV');
        $b = $this->createCase('601M002INPRV');

        $res = $this->postJson('/api/bulk/execute', ['entity' => 'cases', 'ids' => [$a->id, $b->id], 'action' => 'status',
            'params' => ['status' => 'Granted', 'status_event_date' => now()->subDay()->toDateString()]])->assertOk()->json();
        $this->assertSame(2, $res['ok']);
        $this->assertSame('Granted', $a->fresh()->status);
        $this->assertSame(0, $a->fresh()->stages()->where('status', '!=', 'Completed')->count());   // same side effect as a single edit

        $this->postJson('/api/bulk/execute', ['entity' => 'cases', 'ids' => [$a->id], 'action' => 'assign',
            'params' => ['assigned_manager_id' => $analyst->id]])->assertOk()->assertJson(['ok' => 1]);
        $this->assertSame($analyst->id, (int) $a->fresh()->assigned_manager_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'bulk_cases_assign']);

        // A Patent Analyst: only their CM / SCM cases.
        Sanctum::actingAs($analyst);
        $res = $this->postJson('/api/bulk/execute', ['entity' => 'cases', 'ids' => [$a->id, $b->id], 'action' => 'circle', 'params' => ['circle' => 'B']])->assertOk()->json();
        $this->assertSame(1, $res['ok']);
        $this->assertSame(1, $res['failed']);
        $this->assertFalse(collect($res['results'])->firstWhere('id', $b->id)['ok']);
        $this->postJson('/api/bulk/execute', ['entity' => 'cases', 'ids' => [$b->id], 'action' => 'delete'])->assertForbidden();
    }

    public function test_deadline_shift_and_task_status(): void
    {
        $partner = $this->user('partner', 'p@bulk.test');
        Sanctum::actingAs($partner);
        $case = $this->createCase('601M003INPRV', ['hard_deadline' => '2027-01-10']);
        $this->postJson('/api/bulk/execute', ['entity' => 'cases', 'ids' => [$case->id], 'action' => 'deadline', 'params' => ['shift_days' => 5]])
            ->assertOk()->assertJson(['ok' => 1]);
        $this->assertSame('2027-01-15', substr((string) $case->fresh()->hard_deadline, 0, 10));

        $task = Task::create(['title' => 'Draft claims', 'project_id' => $case->id, 'status' => 'Pending', 'priority' => 'Medium', 'assignee_id' => $partner->id]);
        $this->postJson('/api/bulk/execute', ['entity' => 'tasks', 'ids' => [$task->id], 'action' => 'status', 'params' => ['status' => 'In Progress']])
            ->assertOk()->assertJson(['ok' => 1]);
        $this->assertSame('In Progress', $task->fresh()->status);
    }
}
