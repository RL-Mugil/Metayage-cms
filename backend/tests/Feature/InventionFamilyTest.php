<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\User;
use App\Services\InventionFamilyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventionFamilyTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_client_and_invention_identity_resolves_one_family(): void
    {
        [$first] = $this->matter('C44M001INFIL');
        $second = Project::create([
            'project_code' => 'C44M001USFIL',
            'docket_number' => 'C44M001USFIL',
            'invention_number' => '001',
            'client_id' => $first->client_id,
            'project_name' => 'US filing',
            'project_type' => 'Patent',
            'patent_office_code' => 'US',
            'service_code' => 'FIL',
            'status' => 'Open',
        ]);

        $service = app(InventionFamilyService::class);
        $family = $service->attach($first);
        $this->assertSame($family->id, $service->attach($second)->id);
        $this->assertSame('001', $family->invention_number);
    }

    public function test_partner_creates_immutable_foreign_branch_from_workspace_api(): void
    {
        [$source, $partner] = $this->matter('C44M001INFIL');
        $family = app(InventionFamilyService::class)->attach($source);
        Sanctum::actingAs($partner);

        $response = $this->postJson("/api/invention-families/{$family->id}/engagements", [
            'source_project_id' => $source->id,
            'patent_office_code' => 'EP',
            'service_code' => 'FIL',
            'application_number' => 'EP26123456.7',
        ])->assertCreated();

        $successorId = $response->json('project.id');
        $this->assertSame('C44M001INFIL', $source->fresh()->docket_number);
        $this->assertDatabaseHas('projects', [
            'id' => $successorId,
            'invention_family_id' => $family->id,
            'docket_number' => 'C44M001EPFIL',
            'parent_project_id' => $source->id,
            'lifecycle_template_version' => '2026.1',
            'docket_reviewer_id' => $partner->id,
        ]);
        $this->assertDatabaseHas('project_stages', [
            'project_id' => $successorId,
            'stage_name' => 'European filing intake',
            'sequence_order' => 0,
        ]);
        $this->assertDatabaseMissing('project_stages', [
            'project_id' => $successorId,
            'stage_name' => 'Matter opened',
        ]);
        $this->assertDatabaseHas('patent_applications', [
            'invention_family_id' => $family->id,
            'jurisdiction' => 'EP',
            'application_number' => 'EP26123456.7',
        ]);
        $this->assertDatabaseHas('project_elevations', [
            'project_id' => $successorId,
            'predecessor_project_id' => $source->id,
            'to_docket' => 'C44M001EPFIL',
        ]);
    }

    public function test_service_elevation_creates_successor_and_completes_source(): void
    {
        [$source, $partner] = $this->matter('C44M001INFIL');
        $family = app(InventionFamilyService::class)->attach($source);
        Sanctum::actingAs($partner);

        // Service elevation now runs through the family-engagement API. A same-office
        // successor with complete_source closes the source engagement. (FIL has no
        // configured transition rules, so this elevation takes the ungated path.)
        $response = $this->postJson("/api/invention-families/{$family->id}/engagements", [
            'source_project_id'  => $source->id,
            'patent_office_code' => 'IN',
            'service_code'       => 'FER',
            'complete_source'    => true,
        ])->assertCreated();

        $this->assertSame('C44M001INFER', $response->json('project.docket_number'));
        $this->assertSame('Completed', $source->fresh()->status);
        $this->assertDatabaseHas('projects', ['parent_project_id' => $source->id, 'docket_number' => 'C44M001INFER']);
    }

    public function test_client_cannot_create_family_branch(): void
    {
        [$source] = $this->matter('C44M001INFIL');
        $family = app(InventionFamilyService::class)->attach($source);
        $clientUser = $this->user('client', 'client-family@test.local');
        Sanctum::actingAs($clientUser);

        $this->postJson("/api/invention-families/{$family->id}/engagements", [
            'source_project_id' => $source->id,
            'patent_office_code' => 'US',
            'service_code' => 'FIL',
        ])->assertForbidden();
    }

    /** @return array{Project, User} */
    private function matter(string $docket): array
    {
        $partner = $this->user('partner', uniqid('partner-', true).'@test.local');
        $client = Client::create(['client_code' => 'C44M', 'company_name' => 'Family Client', 'status' => 'Active']);
        $project = Project::create([
            'project_code' => $docket,
            'docket_number' => $docket,
            'invention_number' => '001',
            'client_id' => $client->id,
            'project_name' => 'Shared invention',
            'invention_title' => 'Shared invention',
            'project_type' => 'Patent',
            'patent_office_code' => substr($docket, -5, 2),
            'service_code' => substr($docket, -3),
            'assigned_partner_id' => $partner->id,
            'status' => 'Open',
        ]);
        ProjectStage::create(['project_id' => $project->id, 'stage_name' => 'Matter opened', 'status' => 'In Progress', 'sequence_order' => 0, 'duration_days' => 15]);

        return [$project, $partner];
    }

    private function user(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $email, 'password' => bcrypt('password'), 'role' => $role, 'status' => 'Active']);
    }
}
