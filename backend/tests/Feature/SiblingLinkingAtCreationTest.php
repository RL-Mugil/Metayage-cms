<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Siblings sharing the first 9 characters of the docket number (client code +
 * invention number + patent office code) are the same real-world case and
 * must share a PatentApplication from the moment the second engagement is
 * created — not only once someone later types an application number.
 */
class SiblingLinkingAtCreationTest extends TestCase
{
    use RefreshDatabase;

    private function partner(): User
    {
        return User::create([
            'name' => 'Partner User', 'email' => 'partner@sibling.test',
            'password' => bcrypt('password'), 'role' => 'partner', 'status' => 'Active',
        ]);
    }

    private function client(): Client
    {
        return Client::create([
            'client_code' => '397M', 'client_type' => 'organization',
            'legal_name' => 'Client 397', 'company_name' => 'Client 397',
            'nationality' => 'India', 'status' => 'Active',
        ]);
    }

    private function createProject(Client $client, string $docket, string $office, string $service): array
    {
        return $this->postJson('/api/projects', [
            'record_mode' => 'existing',
            'project_code' => $docket,
            'docket_number' => $docket,
            'client_id' => $client->id,
            'project_name' => "Project {$docket}",
            'project_type' => 'Patent',
            'patent_office_code' => $office,
            'service_code' => $service,
        ])->assertCreated()->json();
    }

    public function test_independently_created_siblings_share_an_application_at_creation_time_with_no_application_number(): void
    {
        Sanctum::actingAs($this->partner());
        $client = $this->client();

        $fer = $this->createProject($client, '397M004INFER', 'IN', 'FER');
        $this->assertNotNull($fer['patent_application_id']);

        $cpt = $this->createProject($client, '397M004INCPT', 'IN', 'CPT');

        $this->assertNull($cpt['application_number']);
        $this->assertSame($fer['patent_application_id'], $cpt['patent_application_id']);
    }

    public function test_a_different_office_code_never_merges_with_the_indian_case(): void
    {
        Sanctum::actingAs($this->partner());
        $client = $this->client();

        $fer = $this->createProject($client, '397M005INFER', 'IN', 'FER');
        $usPrv = $this->createProject($client, '397M005USPRV', 'US', 'PRV');

        $this->assertNotNull($usPrv['patent_application_id']);
        $this->assertNotEquals($fer['patent_application_id'], $usPrv['patent_application_id']);
    }

    public function test_three_siblings_created_in_any_order_all_converge_on_one_shared_application(): void
    {
        Sanctum::actingAs($this->partner());
        $client = $this->client();

        $cpt = $this->createProject($client, '397M006INCPT', 'IN', 'CPT');
        $fer = $this->createProject($client, '397M006INFER', 'IN', 'FER');
        $hrg = $this->createProject($client, '397M006INHRG', 'IN', 'HRG');

        $this->assertSame(1, Project::whereIn('docket_number', ['397M006INCPT', '397M006INFER', '397M006INHRG'])->pluck('patent_application_id')->unique()->count());
        $this->assertSame($cpt['patent_application_id'], $fer['patent_application_id']);
        $this->assertSame($cpt['patent_application_id'], $hrg['patent_application_id']);
    }
}
