<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\PatentApplication;
use App\Models\Payment;
use App\Models\Project;
use App\Models\RenewalSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Renewal approve -> invoice -> proof -> confirm loop, built on PatentInvoiceIn +
 * ClientLedger only. Invoice/Payment (the generic law-firm trio) must never be
 * touched by this flow — confirmed explicitly by the user ("invoice stays the same").
 */
class RenewalActionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function client(): Client
    {
        return Client::create([
            'client_code' => '397M', 'client_type' => 'organization',
            'legal_name' => 'Client 397', 'company_name' => 'Client 397',
            'nationality' => 'India', 'status' => 'Active', 'portal_enabled' => true,
        ]);
    }

    private function portalUser(Client $client, string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role), 'email' => $role . '-' . $client->id . '@test.local',
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'Active',
        ]);
        $client->update(['portal_user_id' => $user->id]);
        return $user;
    }

    private function staff(string $role = 'manager'): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role . '@firm.test',
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'Active',
        ]);
    }

    private function projectWithApplication(Client $client): Project
    {
        $application = PatentApplication::create([
            'client_id' => $client->id, 'title' => 'Test Invention',
            'legal_status' => 'Granted', 'jurisdiction' => 'IN',
        ]);
        return Project::create([
            'project_code' => '397M001INRNF', 'docket_number' => '397M001INRNF',
            'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'Renewal Case',
            'status' => 'Open', 'patent_office_code' => 'IN', 'service_code' => 'RNF',
            'patent_application_id' => $application->id,
        ]);
    }

    public function test_only_client_admin_can_approve_renewal(): void
    {
        $client = $this->client();
        $project = $this->projectWithApplication($client);
        $plainClient = $this->portalUser($client, 'client');
        Sanctum::actingAs($plainClient);

        $this->postJson("/api/projects/{$project->id}/renewals/approve", ['years' => 2])
            ->assertForbidden();
    }

    public function test_client_admin_approve_creates_invoice_ledger_and_leaves_invoice_table_untouched(): void
    {
        $client = $this->client();
        $project = $this->projectWithApplication($client);
        $admin = $this->portalUser($client, 'client_admin');
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/projects/{$project->id}/renewals/approve", ['years' => 2])
            ->assertCreated()
            ->json();

        $this->assertSame('397M001INRNF', $response['docket_number']);
        // Fresh project -> years 3,4 -> real seeded IN standard-tier RNF rates
        // (client has no fee_entity_tier set, so 'standard'): govt 4000/yr x2 = 8000, professional flat 5000.
        $this->assertEquals(13000.0, (float) $response['invoice_amount']);
        $this->assertSame('Pending', $response['payment_status']);

        $this->assertDatabaseHas('patent_invoices_in', [
            'docket_number' => '397M001INRNF', 'type' => 'invoice', 'payment_status' => 'Pending',
        ]);

        // Two new RenewalSchedule rows created and linked to the invoice.
        $this->assertSame(2, RenewalSchedule::where('patent_invoice_in_id', $response['id'])->count());

        // Ledger debit for the invoice amount.
        $this->assertDatabaseHas('client_ledger', [
            'client_id' => $client->id, 'document_type' => 'Invoice', 'document_reference' => $response['invoice_uin'],
        ]);
        $ledgerRow = DB::table('client_ledger')->where('client_id', $client->id)->first();
        $this->assertEquals(13000.0, (float) $ledgerRow->debit);

        // Approval self-resolved.
        $this->assertDatabaseHas('approvals', [
            'client_id' => $client->id, 'subject_type' => 'PatentInvoiceIn', 'subject_id' => $response['id'], 'status' => 'Approved',
        ]);

        // Invoice/Payment (generic trio) must never be touched by this flow.
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_full_proof_and_confirm_cycle(): void
    {
        $client = $this->client();
        $project = $this->projectWithApplication($client);
        $admin = $this->portalUser($client, 'client_admin');
        Sanctum::actingAs($admin);

        $invoice = $this->postJson("/api/projects/{$project->id}/renewals/approve", ['years' => 1])->json();

        // Finance-only client role submits proof.
        $finance = $this->portalUser($client, 'client_finance');
        $doc = Document::create([
            'client_id' => $client->id, 'file_name' => 'proof.png', 'file_type' => 'image/png',
            'file_size' => 100, 'category' => 'Payment Proof', 'storage_path' => 'documents/Payment Proof/proof.png',
            'current_version' => 1, 'uploaded_by_id' => $finance->id, 'status' => 'Draft',
        ]);
        Sanctum::actingAs($finance);
        $this->postJson("/api/pending-payments/{$invoice['id']}/proof", ['document_id' => $doc->id])
            ->assertOk()
            ->assertJsonPath('payment_status', 'Proof Submitted');

        // Staff confirms.
        $manager = $this->staff('manager');
        Sanctum::actingAs($manager);
        $this->postJson("/api/pending-payments/{$invoice['id']}/confirm")
            ->assertOk()
            ->assertJsonPath('payment_status', 'Confirmed');

        $this->assertDatabaseHas('renewal_schedules', ['patent_invoice_in_id' => $invoice['id'], 'status' => 'Paid']);
        $this->assertDatabaseHas('client_ledger', ['client_id' => $client->id, 'document_type' => 'Payment', 'document_reference' => $invoice['invoice_uin']]);

        // Cannot confirm twice.
        $this->postJson("/api/pending-payments/{$invoice['id']}/confirm")->assertStatus(422);
    }

    public function test_status_note_visible_to_client_and_staff(): void
    {
        $client = $this->client();
        $project = $this->projectWithApplication($client);
        $admin = $this->portalUser($client, 'client_admin');
        Sanctum::actingAs($admin);
        $invoice = $this->postJson("/api/projects/{$project->id}/renewals/approve", ['years' => 1])->json();

        $this->postJson("/api/pending-payments/{$invoice['id']}/status-note", ['status_note' => 'Paying by 13 Aug'])
            ->assertOk()
            ->assertJsonPath('status_note', 'Paying by 13 Aug');

        $otherClient = Client::create([
            'client_code' => '601M', 'client_type' => 'organization',
            'legal_name' => 'Other Client', 'company_name' => 'Other Client',
            'nationality' => 'India', 'status' => 'Active', 'portal_enabled' => true,
        ]);
        $other = $this->portalUser($otherClient, 'client_admin');
        Sanctum::actingAs($other);
        $this->postJson("/api/pending-payments/{$invoice['id']}/status-note", ['status_note' => 'nope'])
            ->assertForbidden();
    }
}
