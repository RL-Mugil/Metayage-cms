<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PatentApplication;
use App\Models\Project;
use App\Models\User;
use App\Services\ApplicationNumberSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Follow-ups from the IPO status view: legal_status must reflect the real
 * case outcome (not a hardcoded 'Pending' default) on backfill, a partial
 * project update must never wipe the IPO status fields it doesn't mention,
 * and RenewalActionController::confirmReceipt() must record when payment
 * was actually confirmed (feeds the E-Register renewal table).
 */
class ApplicationStatusFollowupTest extends TestCase
{
    use RefreshDatabase;

    private function client(): Client
    {
        return Client::create([
            'client_code' => '397M', 'client_type' => 'organization',
            'legal_name' => 'Client 397', 'company_name' => 'Client 397',
            'nationality' => 'India', 'status' => 'Active',
        ]);
    }

    private function manager(): User
    {
        return User::create([
            'name' => 'Manager', 'email' => 'manager@firm.test',
            'password' => bcrypt('password'), 'role' => 'manager', 'status' => 'Active',
        ]);
    }

    public function test_backfill_derives_legal_status_from_granted_project_instead_of_hardcoding_pending(): void
    {
        $client = $this->client();
        $project = Project::create([
            'project_code' => '397M001INFFP', 'docket_number' => '397M001INFFP',
            'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'Test',
            'status' => 'Granted', 'patent_granted' => true, 'patent_office_code' => 'IN', 'service_code' => 'FFP',
        ]);

        app(ApplicationNumberSyncService::class)->backfill($project, 'IN202341001234');

        $this->assertSame('Granted', $project->fresh()->patentApplication->legal_status);
    }

    public function test_fix_legal_status_command_corrects_pending_applications_with_real_outcomes(): void
    {
        $client = $this->client();
        $application = PatentApplication::create([
            'client_id' => $client->id, 'title' => 'Test', 'legal_status' => 'Pending', 'jurisdiction' => 'IN',
        ]);
        Project::create([
            'project_code' => '397M002INFFP', 'docket_number' => '397M002INFFP',
            'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'Test',
            'status' => 'Refused', 'patent_application_id' => $application->id,
            'patent_office_code' => 'IN', 'service_code' => 'FFP',
        ]);

        Artisan::call('patents:fix-legal-status');

        $this->assertSame('Refused', $application->fresh()->legal_status);
    }

    public function test_partial_project_update_does_not_wipe_ipo_status_fields(): void
    {
        $client = $this->client();
        $manager = $this->manager();
        $application = PatentApplication::create([
            'client_id' => $client->id, 'title' => 'Test', 'legal_status' => 'Granted', 'jurisdiction' => 'IN',
            'application_type' => 'Ordinary Application', 'certificate_issue_date' => '2026-07-15',
        ]);
        $project = Project::create([
            'project_code' => '397M003INFFP', 'docket_number' => '397M003INFFP',
            'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'Test',
            'status' => 'Open', 'patent_application_id' => $application->id,
            'assigned_manager_id' => $manager->id, 'patent_office_code' => 'IN', 'service_code' => 'FFP',
        ]);
        Sanctum::actingAs($manager);

        // A partial update (e.g. a Kanban-style status change) that never mentions
        // the IPO fields must leave them untouched.
        $this->putJson("/api/projects/{$project->id}", ['urgency' => 'High'])->assertOk();

        $this->assertSame('Ordinary Application', $application->fresh()->application_type);
        $this->assertNotNull($application->fresh()->certificate_issue_date);
    }

    public function test_confirm_receipt_records_payment_confirmed_at(): void
    {
        $client = $this->client();
        $application = PatentApplication::create([
            'client_id' => $client->id, 'title' => 'Test', 'legal_status' => 'Granted', 'jurisdiction' => 'IN',
        ]);
        $project = Project::create([
            'project_code' => '397M004INRNF', 'docket_number' => '397M004INRNF',
            'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'Test',
            'status' => 'Open', 'patent_application_id' => $application->id,
            'patent_office_code' => 'IN', 'service_code' => 'RNF',
        ]);
        \DB::table('system_settings')->updateOrInsert(
            ['key' => 'renewal_fee_rates'],
            ['value' => json_encode(['government_fee' => 5000, 'professional_fee' => 3000, 'currency' => 'INR']), 'updated_at' => now()]
        );
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@397.test', 'password' => bcrypt('password'),
            'role' => 'client_admin', 'status' => 'Active',
        ]);
        $client->update(['portal_user_id' => $admin->id, 'portal_enabled' => true]);
        Sanctum::actingAs($admin);

        $invoice = $this->postJson("/api/projects/{$project->id}/renewals/approve", ['years' => 1])->json();

        $manager = $this->manager();
        Sanctum::actingAs($manager);
        $this->postJson("/api/pending-payments/{$invoice['id']}/confirm")->assertOk();

        $this->assertNotNull(\App\Models\PatentInvoiceIn::find($invoice['id'])->payment_confirmed_at);
    }
}
