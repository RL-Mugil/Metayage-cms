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

    public function test_partner_can_create_a_canonical_trademark_asset(): void
    {
        $partner = $this->user('partner', 'asset@docket.test');
        $client = $this->client();
        Sanctum::actingAs($partner);

        $this->postJson('/api/ip-records', [
            'client_id' => $client->id, 'record_type' => 'Trademark', 'jurisdiction' => 'in',
            'title' => 'MYIPSTRATEGY', 'responsible_user_id' => $partner->id,
            'trademark' => ['application_number' => '7654321', 'mark_text' => 'MYIPSTRATEGY', 'nice_classes' => [42]],
        ])->assertCreated()->assertJsonPath('data.record_type', 'Trademark')->assertJsonPath('data.jurisdiction', 'IN');

        $this->assertDatabaseHas('ip_records', ['client_id' => $client->id, 'record_type' => 'Trademark', 'title' => 'MYIPSTRATEGY']);
        $this->assertDatabaseHas('trademark_applications', ['application_number' => '7654321']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'create', 'subject_type' => 'IpRecord']);
    }

    public function test_ip_portfolio_returns_and_searches_linked_uins(): void
    {
        $partner = $this->user('partner', 'portfolio@docket.test');
        $client = $this->client();
        $record = IpRecord::create([
            'client_id' => $client->id,
            'record_code' => 'IPR-2026-00001',
            'record_type' => 'Patent',
            'jurisdiction' => 'IN',
            'title' => 'Linked invention',
            'legal_status' => 'Pending',
        ]);
        Project::create([
            'ip_record_id' => $record->id,
            'project_code' => 'DCK002INFER',
            'docket_number' => 'DCK002INFER',
            'client_id' => $client->id,
            'project_type' => 'Patent',
            'project_name' => 'Linked FER response',
            'patent_office_code' => 'IN',
            'service_code' => 'FER',
            'status' => 'Open',
        ]);
        Sanctum::actingAs($partner);

        $this->getJson('/api/ip-records?search=DCK002INFER')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.uins.0', 'DCK002INFER');
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
