<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\IpRecord;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocketFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_specific_reminder_is_only_visible_and_actionable_by_creator_and_assignee(): void
    {
        $creator = $this->user('manager', 'creator@docket.test');
        $assignee = $this->user('associate', 'assignee@docket.test');
        $outsider = $this->user('associate', 'outsider@docket.test');

        Sanctum::actingAs($creator);
        $response = $this->postJson('/api/reminders', [
            'title' => 'File FER response', 'category' => 'Deadline', 'due_date' => '2026-09-10',
            'scope' => 'user', 'assigned_user_id' => $assignee->id,
        ])->assertCreated()->assertJsonPath('data.assignedUserId', $assignee->id);
        $reminderId = $response->json('data.id');

        Sanctum::actingAs($assignee);
        $this->getJson('/api/reminders')->assertOk()->assertJsonFragment(['id' => $reminderId]);
        $this->putJson("/api/reminders/{$reminderId}", ['completed' => true])->assertOk();

        Sanctum::actingAs($outsider);
        $this->getJson('/api/reminders')->assertOk()->assertJsonMissing(['id' => $reminderId]);
        $this->putJson("/api/reminders/{$reminderId}", ['completed' => false])->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'reminder_create', 'subject_id' => $reminderId]);
    }

    public function test_event_immediately_generates_a_deadline_without_approval(): void
    {
        $partner = $this->user('partner', 'partner@docket.test');
        $project = $this->project();
        Sanctum::actingAs($partner);

        $response = $this->postJson("/api/projects/{$project->id}/docket/events", [
            'event_type' => 'fer_received', 'event_date' => '2026-08-26',
        ])->assertCreated()->assertJsonPath('deadlines.0.due_date', '2027-02-26T00:00:00.000000Z');

        $eventId = $response->json('id');
        $deadlineId = $response->json('deadlines.0.id');
        $this->assertDatabaseHas('docket_events', ['id' => $eventId, 'project_id' => $project->id]);
        $this->assertDatabaseHas('docket_deadlines', ['id' => $deadlineId, 'source_type' => 'Built-in Rule', 'review_status' => 'Approved']);

        $this->putJson("/api/docket/events/{$eventId}", [
            'event_type' => 'fer_received', 'event_date' => '2026-09-01', 'notes' => 'Corrected receipt date',
        ])->assertOk()->assertJsonPath('deadlines.0.due_date', '2027-03-01T00:00:00.000000Z');

        $this->deleteJson("/api/docket/events/{$eventId}")->assertOk();
        $this->assertDatabaseMissing('docket_events', ['id' => $eventId]);
        $this->assertDatabaseMissing('docket_deadlines', ['docket_event_id' => $eventId]);
    }

    public function test_project_staff_has_full_crud_for_manual_deadlines(): void
    {
        $partner = $this->user('partner', 'deadline-crud@docket.test');
        $project = $this->project();
        Sanctum::actingAs($partner);

        $response = $this->postJson("/api/projects/{$project->id}/docket/deadlines", [
            'title' => 'File response', 'due_date' => '2026-10-15', 'risk_level' => 'High',
        ])->assertCreated()->assertJsonPath('source_type', 'Manual');
        $deadlineId = $response->json('id');

        $this->patchJson("/api/docket/deadlines/{$deadlineId}", [
            'title' => 'File corrected response', 'due_date' => '2026-10-20', 'risk_level' => 'Critical',
        ])->assertOk()->assertJsonPath('title', 'File corrected response');

        $this->deleteJson("/api/docket/deadlines/{$deadlineId}")->assertOk();
        $this->assertDatabaseMissing('docket_deadlines', ['id' => $deadlineId]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'delete_docket_deadline', 'subject_id' => $deadlineId]);
    }

    private function user(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $email, 'password' => bcrypt('password'), 'role' => $role, 'status' => 'Active']);
    }

    private function client(): Client
    {
        return Client::create(['company_name' => 'Docket Client', 'client_code' => 'DCK'.random_int(1000, 9999), 'status' => 'Active']);
    }

    private function project(): Project
    {
        $client = $this->client();
        return Project::create(['project_code' => 'DCK001INFER', 'docket_number' => 'DCK001INFER', 'client_id' => $client->id,
            'project_type' => 'Patent', 'project_name' => 'FER Response', 'patent_office_code' => 'IN', 'service_code' => 'FER', 'status' => 'Open']);
    }
}
