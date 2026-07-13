<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'associate'): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => uniqid($role . '-', true) . '@test.local',
            'password' => bcrypt('password'),
            'role' => $role,
            'status' => 'Active',
        ]);
    }

    private function client(): Client
    {
        return Client::create([
            'client_code' => 'C' . fake()->unique()->numerify('###') . 'M',
            'company_name' => fake()->company(),
            'legal_name' => fake()->company(),
            'client_type' => 'organization',
            'nationality' => 'India',
            'state' => 'Tamil Nadu',
            'gst_type' => 'B2B',
            'status' => 'Active',
        ]);
    }

    private function project(Client $client, array $overrides = []): Project
    {
        return Project::create(array_merge([
            'project_code' => 'PRJ-2026-' . fake()->unique()->numerify('#####'),
            'client_id' => $client->id,
            'project_type' => 'Patent',
            'project_name' => fake()->sentence(3),
            'docket_number' => 'C00M' . fake()->unique()->numerify('###') . 'INPAT',
            'patent_office_code' => 'IN',
            'service_code' => 'PAT',
            'status' => 'Open',
            'urgency' => 'Normal',
        ], $overrides));
    }

    public function test_associate_can_create_invoice_for_assigned_case(): void
    {
        $associate = $this->user('associate');
        $client = $this->client();
        $project = $this->project($client, ['patent_engineer_id' => $associate->id]);

        Sanctum::actingAs($associate);

        $response = $this->postJson('/api/financial/invoices', [
            'client_id' => $client->id,
            'project_id' => $project->id,
            'due_date' => '2026-07-31',
            'items' => [
                ['description' => 'Drafting fees', 'amount' => 1000],
            ],
            'currency' => 'INR',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('invoices', [
            'id' => $response->json('id'),
            'client_id' => $client->id,
            'project_id' => $project->id,
            'status' => 'Draft',
        ]);
    }

    public function test_associate_cannot_create_invoice_for_unassigned_case(): void
    {
        $associate = $this->user('associate');
        $client = $this->client();
        $project = $this->project($client);

        Sanctum::actingAs($associate);

        $this->postJson('/api/financial/invoices', [
            'client_id' => $client->id,
            'project_id' => $project->id,
            'due_date' => '2026-07-31',
            'items' => [
                ['description' => 'Drafting fees', 'amount' => 1000],
            ],
            'currency' => 'INR',
        ])->assertForbidden();
    }

    public function test_associate_financial_lists_are_scoped_to_assigned_cases(): void
    {
        $associate = $this->user('associate');
        $client = $this->client();
        $assignedProject = $this->project($client, ['patent_engineer_id' => $associate->id]);
        $otherProject = $this->project($client);

        $visibleInvoice = Invoice::create([
            'invoice_code' => 'INV-2026-00001',
            'client_id' => $client->id,
            'project_id' => $assignedProject->id,
            'issue_date' => '2026-07-13',
            'due_date' => '2026-07-31',
            'currency' => 'INR',
            'subtotal' => 1000,
            'tax_amount' => 180,
            'total_amount' => 1180,
            'balance_due' => 1180,
            'payment_terms' => 'Net 30',
            'status' => 'Draft',
        ]);

        Invoice::create([
            'invoice_code' => 'INV-2026-00002',
            'client_id' => $client->id,
            'project_id' => $otherProject->id,
            'issue_date' => '2026-07-13',
            'due_date' => '2026-07-31',
            'currency' => 'INR',
            'subtotal' => 1000,
            'tax_amount' => 180,
            'total_amount' => 1180,
            'balance_due' => 1180,
            'payment_terms' => 'Net 30',
            'status' => 'Draft',
        ]);

        $visibleQuotation = Quotation::create([
            'quote_code' => 'QUO-2026-00001',
            'client_id' => $client->id,
            'project_id' => $assignedProject->id,
            'valid_until' => '2026-08-15',
            'fee_structure' => 'Fixed Fee',
            'estimated_hours' => 0,
            'estimated_disbursements' => 0,
            'buffer_percentage' => 0,
            'subtotal' => 1000,
            'tax_amount' => 180,
            'total_amount' => 1180,
            'currency' => 'INR',
            'status' => 'Draft',
        ]);

        Quotation::create([
            'quote_code' => 'QUO-2026-00002',
            'client_id' => $client->id,
            'project_id' => $otherProject->id,
            'valid_until' => '2026-08-15',
            'fee_structure' => 'Fixed Fee',
            'estimated_hours' => 0,
            'estimated_disbursements' => 0,
            'buffer_percentage' => 0,
            'subtotal' => 1000,
            'tax_amount' => 180,
            'total_amount' => 1180,
            'currency' => 'INR',
            'status' => 'Draft',
        ]);

        Sanctum::actingAs($associate);

        $invoiceResponse = $this->getJson('/api/financial/invoices');
        $invoiceResponse->assertOk();
        $this->assertSame([$visibleInvoice->id], array_column($invoiceResponse->json('data'), 'id'));

        $quotationResponse = $this->getJson('/api/financial/quotations');
        $quotationResponse->assertOk();
        $this->assertSame([$visibleQuotation->id], array_column($quotationResponse->json('data'), 'id'));
    }
}
