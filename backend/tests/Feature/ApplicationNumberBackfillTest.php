<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DocketDeadline;
use App\Models\DocketEvent;
use App\Models\PatentApplication;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The "case merge" from the 397/269 pilot plan: entering an application number
 * on one engagement backfills it onto every sibling sharing the same docket
 * prefix (client + invention + patent office), grouping them under one shared
 * PatentApplication. A different jurisdiction must never merge.
 */
class ApplicationNumberBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function partner(): User
    {
        return User::create([
            'name' => 'Partner User', 'email' => 'partner@test.local',
            'password' => bcrypt('password'), 'role' => 'partner', 'status' => 'Active',
        ]);
    }

    private function client397(): Client
    {
        return Client::create([
            'client_code' => '397M', 'client_type' => 'organization',
            'legal_name' => 'Client 397', 'company_name' => 'Client 397',
            'nationality' => 'India', 'status' => 'Active',
        ]);
    }

    public function test_application_number_backfills_across_same_jurisdiction_siblings_but_not_other_jurisdictions(): void
    {
        Sanctum::actingAs($this->partner());
        $client = $this->client397();

        $fer = Project::create([
            'project_code' => '397M001INFER', 'docket_number' => '397M001INFER',
            'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'FER',
            'patent_office_code' => 'IN', 'service_code' => 'FER', 'invention_number' => '001',
        ]);
        $cpt = Project::create([
            'project_code' => '397M001INCPT', 'docket_number' => '397M001INCPT',
            'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'CPT',
            'patent_office_code' => 'IN', 'service_code' => 'CPT', 'invention_number' => '001',
        ]);
        $us = Project::create([
            'project_code' => '397M001USREN', 'docket_number' => '397M001USREN',
            'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'US Renewal',
            'patent_office_code' => 'US', 'service_code' => 'REN', 'invention_number' => '001',
        ]);

        $response = $this->putJson("/api/projects/{$fer->id}", ['application_number' => 'IN202341001234'])
            ->assertOk()
            ->json();

        $this->assertSame('397M001INCPT', $response['application_number_backfilled'][0]['docket_number']);

        $cpt->refresh();
        $fer->refresh();
        $us->refresh();

        $this->assertSame('IN202341001234', $cpt->application_number);
        $this->assertNotNull($cpt->patent_application_id);
        $this->assertSame($fer->patent_application_id, $cpt->patent_application_id);

        // A different jurisdiction (US) for the same client/invention is never touched.
        $this->assertNull($us->application_number);
        $this->assertNotEquals($fer->patent_application_id, $us->patent_application_id);
    }

    public function test_backfill_reuses_an_existing_shared_application_instead_of_creating_a_second_one(): void
    {
        Sanctum::actingAs($this->partner());
        $client = $this->client397();

        $application = PatentApplication::create([
            'client_id' => $client->id, 'title' => 'Existing App',
            'legal_status' => 'Pending', 'jurisdiction' => 'IN',
        ]);

        $fer = Project::create([
            'project_code' => '397M002INFER', 'docket_number' => '397M002INFER',
            'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'FER',
            'patent_office_code' => 'IN', 'service_code' => 'FER', 'invention_number' => '002',
            'patent_application_id' => $application->id,
        ]);
        $cpt = Project::create([
            'project_code' => '397M002INCPT', 'docket_number' => '397M002INCPT',
            'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'CPT',
            'patent_office_code' => 'IN', 'service_code' => 'CPT', 'invention_number' => '002',
        ]);

        $this->putJson("/api/projects/{$cpt->id}", ['application_number' => 'IN202341009999'])->assertOk();

        $this->assertSame(1, PatentApplication::count());
        $this->assertSame($application->id, $cpt->fresh()->patent_application_id);
        $this->assertSame('IN202341009999', $application->fresh()->application_number);
        $this->assertSame('IN202341009999', $fer->fresh()->application_number);
    }

    public function test_backfill_retags_historical_docket_events_and_deadlines_on_the_newly_linked_sibling(): void
    {
        Sanctum::actingAs($this->partner());
        $client = $this->client397();

        $fer = Project::create([
            'project_code' => '397M003INFER', 'docket_number' => '397M003INFER',
            'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'FER',
            'patent_office_code' => 'IN', 'service_code' => 'FER', 'invention_number' => '003',
        ]);
        $cpt = Project::create([
            'project_code' => '397M003INCPT', 'docket_number' => '397M003INCPT',
            'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'CPT',
            'patent_office_code' => 'IN', 'service_code' => 'CPT', 'invention_number' => '003',
        ]);

        // History recorded on CPT before the two engagements are linked — no
        // patent_application_id yet, since CPT has none.
        $event = DocketEvent::create([
            'project_id' => $cpt->id, 'event_type' => 'application_filed', 'event_date' => '2026-01-10',
        ]);
        $deadline = DocketDeadline::create([
            'project_id' => $cpt->id, 'docket_event_id' => $event->id,
            'title' => 'Publication Expected', 'due_date' => '2027-07-10', 'status' => 'Open',
        ]);

        $this->putJson("/api/projects/{$fer->id}", ['application_number' => 'IN202341003456'])->assertOk();

        $sharedApplicationId = $fer->fresh()->patent_application_id;
        $this->assertNotNull($sharedApplicationId);
        $this->assertSame($sharedApplicationId, $cpt->fresh()->patent_application_id);

        $this->assertSame($sharedApplicationId, $event->fresh()->patent_application_id);
        $this->assertSame($sharedApplicationId, $deadline->fresh()->patent_application_id);
    }

    public function test_manual_renewal_year_can_be_added_for_non_in_application(): void
    {
        $partner = $this->partner();
        Sanctum::actingAs($partner);
        $client = $this->client397();

        $application = PatentApplication::create([
            'client_id' => $client->id, 'title' => 'US Case',
            'legal_status' => 'Granted', 'jurisdiction' => 'US',
        ]);
        $project = Project::create([
            'project_code' => '397M001USREN', 'docket_number' => '397M001USREN',
            'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'US Renewal',
            'patent_office_code' => 'US', 'service_code' => 'REN', 'invention_number' => '001',
            'patent_application_id' => $application->id,
        ]);

        $this->postJson("/api/projects/{$project->id}/docket/renewals", [
            'renewal_year' => 4,
            'due_date' => '2027-01-15',
        ])->assertCreated()->assertJsonFragment(['renewal_year' => 4, 'status' => 'Unpaid']);

        $this->assertDatabaseHas('renewal_schedules', [
            'patent_application_id' => $application->id,
            'renewal_year' => 4,
            'status' => 'Unpaid',
        ]);
    }
}
