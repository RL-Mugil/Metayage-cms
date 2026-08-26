<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FeeRateCard;
use App\Models\PatentApplication;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeeRateCardTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@firm.test', 'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'Active']);
    }

    private function manager(): User
    {
        return User::create(['name' => 'Manager', 'email' => 'manager@firm.test', 'password' => bcrypt('password'), 'role' => 'manager', 'status' => 'Active']);
    }

    public function test_index_is_readable_by_any_staff_but_write_requires_super_admin(): void
    {
        // Real data is seeded by migration — assert against a service code that
        // isn't in the seed set, rather than the full table count.
        FeeRateCard::create(['jurisdiction' => 'IN', 'service_code' => 'ZZZ', 'entity_tier' => 'discounted', 'govt_fee_amount' => 1600, 'govt_fee_currency' => 'INR']);
        Sanctum::actingAs($this->manager());

        $response = $this->getJson('/api/fee-rate-cards')->assertOk()->json();
        $this->assertCount(1, collect($response)->where('service_code', 'ZZZ'));
        $this->postJson('/api/fee-rate-cards', ['jurisdiction' => 'IN', 'service_code' => 'ZZY'])->assertForbidden();
    }

    public function test_super_admin_can_crud_fee_rate_cards(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $created = $this->postJson('/api/fee-rate-cards', [
            'jurisdiction' => 'IN', 'service_code' => 'ZZQ', 'govt_fee_amount' => null,
            'professional_fee_amount' => 35000, 'professional_fee_currency' => 'INR',
        ])->assertCreated()->json();

        $this->putJson("/api/fee-rate-cards/{$created['id']}", ['jurisdiction' => 'IN', 'service_code' => 'ZZQ', 'professional_fee_amount' => 40000])
            ->assertOk()->assertJsonPath('professional_fee_amount', '40000.00');

        $this->deleteJson("/api/fee-rate-cards/{$created['id']}")->assertOk();
        $this->assertDatabaseMissing('fee_rate_cards', ['id' => $created['id']]);
    }

    public function test_discount_applies_to_service_fees_only_before_gst(): void
    {
        $manager = $this->manager();
        Sanctum::actingAs($manager);

        $client = Client::create(['client_code' => '397M', 'client_type' => 'organization', 'legal_name' => 'C', 'company_name' => 'C', 'nationality' => 'India', 'status' => 'Active', 'state' => 'Maharashtra']);
        $project = Project::create(['project_code' => '397M001INPRV', 'docket_number' => '397M001INPRV', 'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'Test', 'status' => 'Open']);

        $response = $this->postJson('/api/patent-invoices/in', [
            'type' => 'invoice', 'project_id' => $project->id, 'docket_number' => $project->docket_number,
            'invoice_date' => now()->toDateString(),
            'patent_office_fees' => 1600, 'service_fees' => 35000, 'discount_percentage' => 10,
            'other_expenses' => 0, 'state_of_supply' => 'Maharashtra',
        ])->assertCreated()->json();

        // Discount: 35000 * 10% = 3500 off; net service fee 31500; IGST 18% of 31500 = 5670.
        $this->assertEquals(3500.0, (float) $response['discount_amount']);
        $this->assertEquals(5670.0, (float) $response['igst_amount']);
        $this->assertEquals(1600 + 31500 + 5670, (float) $response['invoice_amount']);
    }

    public function test_ren_alias_seeded_alongside_rnf(): void
    {
        // Real project data uses 'REN' for renewals, not the dictionary's 'RNF' —
        // the seed must cover both so lookups work regardless of which a case carries.
        $rnfCount = FeeRateCard::where('jurisdiction', 'IN')->where('service_code', 'RNF')->count();
        $renCount = FeeRateCard::where('jurisdiction', 'IN')->where('service_code', 'REN')->count();
        $this->assertGreaterThan(0, $rnfCount);
        $this->assertSame($rnfCount, $renCount);
    }

    public function test_renewal_totals_works_for_ren_service_code(): void
    {
        $client = Client::create(['client_code' => '397M', 'client_type' => 'organization', 'legal_name' => 'C', 'company_name' => 'C', 'nationality' => 'India', 'status' => 'Active']);
        $application = PatentApplication::create(['client_id' => $client->id, 'title' => 'T', 'legal_status' => 'Granted', 'jurisdiction' => 'IN', 'filing_date' => now()->subYears(4)]);
        $project = Project::create(['project_code' => '397M001INREN', 'docket_number' => '397M001INREN', 'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'Test', 'status' => 'Open', 'patent_office_code' => 'IN', 'service_code' => 'REN', 'patent_application_id' => $application->id]);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@397ren.test', 'password' => bcrypt('password'), 'role' => 'client_admin', 'status' => 'Active']);
        $client->update(['portal_user_id' => $admin->id, 'portal_enabled' => true]);
        Sanctum::actingAs($admin);

        // Fresh project -> year 3, standard tier (no fee_entity_tier set): govt 4000, professional flat 5000.
        $invoice = $this->postJson("/api/projects/{$project->id}/renewals/approve", ['years' => 1])->assertCreated()->json();
        $this->assertEquals(9000.0, (float) $invoice['invoice_amount']);
    }

    public function test_renewal_totals_sums_correct_bands_across_a_boundary(): void
    {
        // Relies on the real seeded IN RNF data: years 2-6 govt 800/yr, years 7-10
        // govt 2400/yr, professional fee flat 5000/txn (discounted tier).
        $client = Client::create(['client_code' => '397M', 'client_type' => 'organization', 'legal_name' => 'C', 'company_name' => 'C', 'nationality' => 'India', 'status' => 'Active', 'fee_entity_tier' => 'individual_startup_msme']);
        $application = PatentApplication::create(['client_id' => $client->id, 'title' => 'T', 'legal_status' => 'Granted', 'jurisdiction' => 'IN', 'filing_date' => now()->subYears(6)]);
        $project = Project::create(['project_code' => '397M001INRNF', 'docket_number' => '397M001INRNF', 'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'Test', 'status' => 'Open', 'patent_office_code' => 'IN', 'patent_application_id' => $application->id]);

        // Pre-pay years 3-5 so the next two unpaid years the controller picks are 6 and 7 — spanning the band boundary.
        foreach ([3, 4, 5] as $year) {
            \App\Models\RenewalSchedule::create(['patent_application_id' => $application->id, 'renewal_year' => $year, 'due_date' => now(), 'status' => 'Paid', 'paid_at' => now()]);
        }

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@397.test', 'password' => bcrypt('password'), 'role' => 'client_admin', 'status' => 'Active']);
        $client->update(['portal_user_id' => $admin->id, 'portal_enabled' => true]);
        Sanctum::actingAs($admin);

        $invoice = $this->postJson("/api/projects/{$project->id}/renewals/approve", ['years' => 2])->assertCreated()->json();

        $this->assertEquals(800 + 2400, (float) $invoice['patent_office_fees']);
        // Flat-per-transaction professional fee must not be summed across both bands (5000, not 10000).
        $this->assertEquals(5000.0, (float) $invoice['service_fees']);
    }
}
