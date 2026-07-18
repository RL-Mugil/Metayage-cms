<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DocketDeadline;
use App\Models\DeadlineRuleDefinition;
use App\Models\PatentApplication;
use App\Models\Project;
use App\Models\User;
use App\Support\DocketRules;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MatterWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_user_receives_typed_matter_workspace(): void
    {
        $partner = $this->user('partner', 'partner@workspace.test');
        $project = $this->project();
        Sanctum::actingAs($partner);
        DeadlineRuleDefinition::where('event_type', 'fer_received')->update([
            'status' => 'Approved', 'approved_by' => $partner->id, 'approved_at' => now(),
        ]);

        $this->getJson("/api/projects/{$project->id}/workspace")
            ->assertOk()
            ->assertJsonPath('data.project.id', $project->id)
            ->assertJsonPath('data.capabilities.can_update', true)
            ->assertJsonPath('data.capabilities.can_view_financials', true)
            ->assertJsonStructure(['data' => [
                'project', 'application', 'family', 'family_engagements', 'stages', 'deadlines', 'deadline_summary',
                'events', 'tasks', 'documents', 'related_matters', 'financials',
                'audit', 'timeline', 'capabilities',
            ]]);
    }

    public function test_client_workspace_hides_internal_financial_and_audit_data(): void
    {
        $portalUser = $this->user('client_admin', 'portal@workspace.test');
        $project = $this->project([
            'portal_user_id' => $portalUser->id,
            'portal_enabled' => true,
        ]);
        Sanctum::actingAs($portalUser);

        $this->getJson("/api/projects/{$project->id}/workspace")
            ->assertOk()
            ->assertJsonPath('data.capabilities.can_manage_docket', false)
            ->assertJsonPath('data.capabilities.can_view_financials', false)
            ->assertJsonPath('data.financials', null)
            ->assertJsonCount(0, 'data.audit');
    }

    public function test_client_cannot_open_another_clients_workspace(): void
    {
        $portalUser = $this->user('client', 'outsider@workspace.test');
        $project = $this->project();
        Sanctum::actingAs($portalUser);

        $this->getJson("/api/projects/{$project->id}/workspace")->assertForbidden();
    }

    public function test_new_rule_generated_deadline_records_versioned_provenance(): void
    {
        $partner = $this->user('partner', 'docket@workspace.test');
        $project = $this->project();
        Sanctum::actingAs($partner);

        // Rule definitions are seeded as Draft; only an Approved rule generates a
        // legal deadline (see test_draft_rule_does_not_generate_a_legal_deadline).
        DeadlineRuleDefinition::where('event_type', 'fer_received')->update([
            'status' => 'Approved', 'approved_by' => $partner->id, 'approved_at' => now(),
        ]);

        DocketRules::recordEvent(
            'fer_received',
            Carbon::parse('2026-07-01'),
            $project->id,
            $project->patent_application_id,
            null,
            $partner->id,
        );

        $deadline = DocketDeadline::query()->where('project_id', $project->id)->firstOrFail();
        $this->assertSame('System Rule', $deadline->source_type);
        $this->assertSame('2026.1-candidate', $deadline->rule_version);
        $this->assertSame('fer_received', $deadline->calculation_trace['trigger_event']);
        $this->assertSame('2027-01-01', $deadline->due_date->toDateString());
    }

    public function test_draft_rule_does_not_generate_a_legal_deadline(): void
    {
        $partner = $this->user('partner', 'draft-rule@workspace.test');
        $project = $this->project();

        DocketRules::recordEvent('fer_received', Carbon::parse('2026-07-01'), $project->id, $project->patent_application_id, null, $partner->id);

        $this->assertDatabaseHas('docket_events', ['project_id' => $project->id, 'event_type' => 'fer_received']);
        $this->assertDatabaseMissing('docket_deadlines', ['project_id' => $project->id]);
    }

    private function user(string $role, string $email): User
    {
        return User::create([
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => $role,
            'status' => 'Active',
        ]);
    }

    /** @param array<string, mixed> $clientOverrides */
    private function project(array $clientOverrides = []): Project
    {
        $client = Client::create(array_merge([
            'company_name' => 'Workspace Client',
            'client_code' => 'WSP' . random_int(1000, 9999),
            'status' => 'Active',
        ], $clientOverrides));

        $application = PatentApplication::create([
            'client_id' => $client->id,
            'application_number' => '202641000001',
            'title' => 'Workspace Patent',
            'priority_date' => '2025-07-01',
            'filing_date' => '2026-01-15',
            'legal_status' => 'Under Examination',
            'jurisdiction' => 'IN',
        ]);

        return Project::create([
            'project_code' => 'PRJ-WORK-' . random_int(1000, 9999),
            'docket_number' => 'WSP001INFER',
            'project_name' => 'FER Response',
            'project_type' => 'Patent',
            'case_type' => 'Examination',
            'client_id' => $client->id,
            'patent_application_id' => $application->id,
            'patent_office_code' => 'IN',
            'service_code' => 'FER',
            'status' => 'In Progress',
        ]);
    }
}
