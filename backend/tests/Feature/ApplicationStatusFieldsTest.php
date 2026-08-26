<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PatentApplication;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The four IPO-status-view fields with no auto-generation event
 * (application_type, fer_reply_date, certificate_issue_date, post_grant_journal_date).
 */
class ApplicationStatusFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::create([
            'name' => 'Manager', 'email' => 'manager@firm.test',
            'password' => bcrypt('password'), 'role' => 'manager', 'status' => 'Active',
        ]);
    }

    private function projectWithApplication(): Project
    {
        $client = Client::create([
            'client_code' => '397M', 'client_type' => 'organization',
            'legal_name' => 'Client 397', 'company_name' => 'Client 397',
            'nationality' => 'India', 'status' => 'Active',
        ]);
        $application = PatentApplication::create([
            'client_id' => $client->id, 'title' => 'Test Invention',
            'legal_status' => 'Granted', 'jurisdiction' => 'IN', 'grant_number' => '595508',
        ]);
        $manager = $this->manager();
        return Project::create([
            'project_code' => '397M001INFFP', 'docket_number' => '397M001INFFP',
            'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'Test Invention',
            'status' => 'Open', 'patent_application_id' => $application->id,
            'assigned_manager_id' => $manager->id,
        ]);
    }

    public function test_manager_can_set_ipo_status_fields(): void
    {
        $project = $this->projectWithApplication();
        Sanctum::actingAs(User::where('id', $project->assigned_manager_id)->first());

        $this->patchJson("/api/projects/{$project->id}/docket/application", [
            'application_type' => 'Ordinary Application',
            'fer_reply_date' => '2026-06-09',
            'certificate_issue_date' => '2026-07-15',
            'post_grant_journal_date' => '2026-07-17',
        ])->assertOk()->assertJsonPath('application_type', 'Ordinary Application');

        $this->assertDatabaseHas('patent_applications', [
            'id' => $project->patent_application_id,
            'application_type' => 'Ordinary Application',
            'certificate_issue_date' => '2026-07-15',
            'post_grant_journal_date' => '2026-07-17',
        ]);
    }

    public function test_associate_without_update_permission_is_forbidden(): void
    {
        $project = $this->projectWithApplication();
        $associate = User::create([
            'name' => 'Associate', 'email' => 'associate@firm.test',
            'password' => bcrypt('password'), 'role' => 'associate', 'status' => 'Active',
        ]);
        Sanctum::actingAs($associate);

        $this->patchJson("/api/projects/{$project->id}/docket/application", ['application_type' => 'x'])
            ->assertForbidden();
    }

    public function test_workspace_payload_includes_new_fields_and_client_email(): void
    {
        $project = $this->projectWithApplication();
        $project->client()->update(['contact_email' => 'ipo@myipstrategy.com']);
        $project->patentApplication->update(['fer_reply_date' => '2026-06-09']);
        Sanctum::actingAs(User::where('id', $project->assigned_manager_id)->first());

        $response = $this->getJson("/api/projects/{$project->id}/workspace")->assertOk()->json('data');

        $this->assertSame('2026-06-09', substr($response['application']['fer_reply_date'], 0, 10));
        $this->assertSame('ipo@myipstrategy.com', $response['project']['client']['contact_email']);
    }
}
