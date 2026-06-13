<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientControllerTest extends TestCase
{
    use RefreshDatabase;

    // ──── Helpers ────

    private function user(string $role = 'partner'): User
    {
        static $seq = 0;
        $seq++;
        return User::create([
            'name'     => ucfirst($role) . " User {$seq}",
            'email'    => $role . $seq . '@test.local',
            'password' => bcrypt('password'),
            'role'     => $role,
            'status'   => 'Active',
        ]);
    }

    private function validClientData(array $override = []): array
    {
        static $counter = 0;
        $counter++;
        return array_merge([
            'legal_name'  => "Test Company {$counter} Ltd.",
            'client_type' => 'organization',
            'nationality' => 'India',
            'status'      => 'Active',
        ], $override);
    }

    private function makeClient(array $override = []): Client
    {
        static $code = 0;
        $code++;
        $data = $this->validClientData($override);
        $data['client_code']  = $data['client_code']  ?? 'C' . str_pad($code, 2, '0', STR_PAD_LEFT) . 'M';
        $data['company_name'] = $data['company_name'] ?? $data['legal_name'];

        if (!isset($data['gst_type'])) {
            $nationality = $data['nationality'] ?? 'India';
            $hasGstin    = (bool) ($data['has_gstin'] ?? false);
            $clientType  = $data['client_type'] ?? 'organization';
            if ($nationality !== 'India') {
                $data['gst_type'] = 'Export';
            } elseif ($clientType === 'individual') {
                $data['gst_type'] = 'B2C';
            } elseif ($hasGstin) {
                $data['gst_type'] = 'B2B';
            } else {
                $data['gst_type'] = 'Unregistered';
            }
        }

        return Client::create($data);
    }

    private function csvUpload(string $content, string $name = 'clients.csv'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test_csv_');
        file_put_contents($tmp, $content);
        return new UploadedFile($tmp, $name, 'text/csv', null, true);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 1. AUTHENTICATION
    // ══════════════════════════════════════════════════════════════════════════

    public function test_unauthenticated_user_cannot_list_clients(): void
    {
        $this->getJson('/api/clients')->assertUnauthorized();
    }

    public function test_unauthenticated_user_cannot_create_client(): void
    {
        $this->postJson('/api/clients', $this->validClientData())->assertUnauthorized();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 2. AUTHORIZATION
    // ══════════════════════════════════════════════════════════════════════════

    public function test_associate_cannot_create_client(): void
    {
        Sanctum::actingAs($this->user('associate'));
        $this->postJson('/api/clients', $this->validClientData())->assertForbidden();
    }

    public function test_partner_can_create_client(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $this->postJson('/api/clients', $this->validClientData([
            'legal_name' => 'Acme Corporation Ltd.',
        ]))->assertCreated()->assertJsonFragment(['legal_name' => 'Acme Corporation Ltd.']);
    }

    public function test_super_admin_can_create_client(): void
    {
        Sanctum::actingAs($this->user('super_admin'));
        $this->postJson('/api/clients', $this->validClientData([
            'legal_name' => 'Beta Inc.',
        ]))->assertCreated();
    }

    public function test_manager_can_create_client(): void
    {
        Sanctum::actingAs($this->user('manager'));
        $this->postJson('/api/clients', $this->validClientData([
            'legal_name' => 'Gamma Solutions Ltd.',
        ]))->assertCreated();
    }

    public function test_client_role_cannot_create_client(): void
    {
        Sanctum::actingAs($this->user('client'));
        $this->postJson('/api/clients', $this->validClientData())->assertForbidden();
    }

    public function test_associate_cannot_update_client(): void
    {
        $partner   = $this->user('partner');
        $associate = $this->user('associate');

        Sanctum::actingAs($partner);
        $client = $this->makeClient(['legal_name' => 'Original Name']);

        Sanctum::actingAs($associate);
        $this->putJson("/api/clients/{$client->id}", ['legal_name' => 'Hacked Name'])->assertForbidden();
        $this->assertEquals('Original Name', $client->fresh()->legal_name);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 3. VALIDATION
    // ══════════════════════════════════════════════════════════════════════════

    public function test_create_client_requires_legal_name(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $this->postJson('/api/clients', [])->assertStatus(422);
        $this->postJson('/api/clients', ['client_type' => 'organization'])->assertStatus(422);
        $this->postJson('/api/clients', $this->validClientData(['legal_name' => null]))->assertStatus(422);
        $this->postJson('/api/clients', $this->validClientData(['legal_name' => '']))->assertStatus(422);
    }

    public function test_create_client_requires_client_type(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $data = $this->validClientData();
        unset($data['client_type']);
        $this->postJson('/api/clients', $data)->assertStatus(422);
    }

    public function test_create_client_rejects_invalid_client_type(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $this->postJson('/api/clients', $this->validClientData(['client_type' => 'corporation']))->assertStatus(422);
    }

    public function test_create_client_with_optional_fields(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $response = $this->postJson('/api/clients', $this->validClientData([
            'website'       => 'https://example.com',
            'pan_number'    => 'ABCDE1234F',
            'contact_email' => 'test@example.com',
        ]))->assertCreated();
        $this->assertNotNull($response->json('id'));
    }

    public function test_contact_email_must_be_valid_on_create(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $this->postJson('/api/clients', $this->validClientData([
            'contact_email' => 'not-an-email',
        ]))->assertStatus(422);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 4. CLIENT CODE AUTO-GENERATION
    // ══════════════════════════════════════════════════════════════════════════

    public function test_client_code_is_auto_generated_on_create(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $response = $this->postJson('/api/clients', $this->validClientData())->assertCreated()->json();
        $this->assertNotNull($response['client_code']);
        $this->assertMatchesRegularExpression('/^[C-Z]\d{2}[MY]$/', $response['client_code']);
    }

    public function test_client_code_has_m_suffix_for_india(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $response = $this->postJson('/api/clients', $this->validClientData([
            'nationality' => 'India',
        ]))->assertCreated()->json();
        $this->assertStringEndsWith('M', $response['client_code']);
    }

    public function test_client_code_has_y_suffix_for_foreign_client(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $response = $this->postJson('/api/clients', $this->validClientData([
            'nationality' => 'USA',
        ]))->assertCreated()->json();
        $this->assertStringEndsWith('Y', $response['client_code']);
    }

    public function test_sequential_client_codes_do_not_collide(): void
    {
        Sanctum::actingAs($this->user('partner'));

        $c1 = $this->postJson('/api/clients', $this->validClientData())->assertCreated()->json('client_code');
        $c2 = $this->postJson('/api/clients', $this->validClientData())->assertCreated()->json('client_code');
        $this->assertNotEquals($c1, $c2);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 5. GST TYPE COMPUTATION
    // ══════════════════════════════════════════════════════════════════════════

    public function test_gst_type_b2b_for_indian_org_with_gstin(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $response = $this->postJson('/api/clients', $this->validClientData([
            'nationality'  => 'India',
            'client_type'  => 'organization',
            'has_gstin'    => true,
            'gstin'        => '27AAPFU0939F1ZV',
        ]))->assertCreated()->json();
        $this->assertEquals('B2B', $response['gst_type']);
    }

    public function test_gst_type_b2c_for_indian_individual_without_gstin(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $response = $this->postJson('/api/clients', $this->validClientData([
            'nationality' => 'India',
            'client_type' => 'individual',
            'has_gstin'   => false,
        ]))->assertCreated()->json();
        $this->assertEquals('B2C', $response['gst_type']);
    }

    public function test_gst_type_unregistered_for_indian_org_without_gstin(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $response = $this->postJson('/api/clients', $this->validClientData([
            'nationality' => 'India',
            'client_type' => 'organization',
            'has_gstin'   => false,
        ]))->assertCreated()->json();
        $this->assertEquals('Unregistered', $response['gst_type']);
    }

    public function test_gst_type_export_for_foreign_client(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $response = $this->postJson('/api/clients', $this->validClientData([
            'nationality' => 'USA',
            'client_type' => 'organization',
            'has_gstin'   => false,
        ]))->assertCreated()->json();
        $this->assertEquals('Export', $response['gst_type']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 6. INDIVIDUAL VS ORGANIZATION FIELDS
    // ══════════════════════════════════════════════════════════════════════════

    public function test_individual_client_type_is_stored(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $response = $this->postJson('/api/clients', $this->validClientData([
            'legal_name'  => 'Priya Sharma',
            'client_type' => 'individual',
        ]))->assertCreated()->json();
        $this->assertEquals('individual', $response['client_type']);
    }

    public function test_organization_fields_stored_correctly(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $response = $this->postJson('/api/clients', $this->validClientData([
            'client_type'    => 'organization',
            'entity_subtype' => 'Private Limited (Pvt Ltd)',
            'trade_name'     => 'Acme Brand',
            'cin_number'     => 'U12345MH2020PTC123456',
            'website'        => 'https://acme.com',
        ]))->assertCreated()->json();
        $this->assertEquals('Private Limited (Pvt Ltd)', $response['entity_subtype']);
        $this->assertEquals('Acme Brand', $response['trade_name']);
        $this->assertEquals('U12345MH2020PTC123456', $response['cin_number']);
    }

    public function test_all_contact_fields_stored_correctly(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $response = $this->postJson('/api/clients', $this->validClientData([
            'contact_name'  => 'John Doe',
            'contact_email' => 'john@acme.com',
            'phone'         => '+91 98765 43210',
            'address'       => '123 Main St, Mumbai',
            'state'         => 'Maharashtra',
        ]))->assertCreated()->json();
        $this->assertEquals('John Doe',        $response['contact_name']);
        $this->assertEquals('john@acme.com',   $response['contact_email']);
        $this->assertEquals('+91 98765 43210', $response['phone']);
        $this->assertEquals('Maharashtra',     $response['state']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 7. LIST, SEARCH & FILTER
    // ══════════════════════════════════════════════════════════════════════════

    public function test_list_clients_returns_paginated_results(): void
    {
        Sanctum::actingAs($this->user('partner'));
        for ($i = 1; $i <= 30; $i++) {
            $this->makeClient(['legal_name' => "Paginated Client {$i}"]);
        }
        $response = $this->getJson('/api/clients')->assertOk()->json();
        $this->assertIsArray($response['data']);
        $this->assertLessThanOrEqual(25, count($response['data']));
        $this->assertGreaterThan(1, $response['last_page']);
    }

    public function test_list_clients_search_by_company_name(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $this->makeClient(['legal_name' => 'Searchable Acme Corp']);
        $this->makeClient(['legal_name' => 'Other Company Ltd']);

        $response = $this->getJson('/api/clients?search=Acme')->assertOk()->json();
        $this->assertGreaterThan(0, count($response['data']));
        $this->assertStringContainsStringIgnoringCase('Acme', $response['data'][0]['company_name']);
    }

    public function test_list_clients_filter_by_status(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $this->makeClient(['legal_name' => 'Active Corp',   'status' => 'Active']);
        $this->makeClient(['legal_name' => 'Inactive Corp', 'status' => 'Inactive']);

        $active = $this->getJson('/api/clients?status=Active')->assertOk()->json('data');
        foreach ($active as $c) {
            $this->assertEquals('Active', $c['status']);
        }
    }

    public function test_list_clients_filter_by_gst_type(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $this->makeClient(['legal_name' => 'B2B Corp',    'gst_type' => 'B2B',    'has_gstin' => true]);
        $this->makeClient(['legal_name' => 'Export Corp', 'gst_type' => 'Export', 'nationality' => 'USA']);

        $b2b = $this->getJson('/api/clients?gst_type=B2B')->assertOk()->json('data');
        foreach ($b2b as $c) {
            $this->assertEquals('B2B', $c['gst_type']);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 8. UPDATE
    // ══════════════════════════════════════════════════════════════════════════

    public function test_partner_can_update_client(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $client = $this->makeClient(['legal_name' => 'Original Name']);

        $this->putJson("/api/clients/{$client->id}", [
            'legal_name' => 'Updated Name Corp',
        ])->assertOk()->assertJsonFragment(['legal_name' => 'Updated Name Corp']);
    }

    public function test_update_recomputes_gst_type(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $client = $this->makeClient([
            'nationality' => 'India',
            'client_type' => 'organization',
            'has_gstin'   => false,
        ]);
        $this->assertEquals('Unregistered', $client->gst_type);

        $this->putJson("/api/clients/{$client->id}", ['has_gstin' => true])->assertOk();
        $this->assertEquals('B2B', $client->fresh()->gst_type);
    }

    public function test_client_status_transitions(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $client = $this->makeClient(['status' => 'Active']);

        $this->putJson("/api/clients/{$client->id}", ['status' => 'Inactive'])->assertOk();
        $this->assertEquals('Inactive', $client->fresh()->status);

        $this->putJson("/api/clients/{$client->id}", ['status' => 'Active'])->assertOk();
        $this->assertEquals('Active', $client->fresh()->status);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 9. DELETE
    // ══════════════════════════════════════════════════════════════════════════

    public function test_delete_client_with_invoices_is_blocked(): void
    {
        $user = $this->user('super_admin');
        Sanctum::actingAs($user);

        $client = $this->makeClient(['legal_name' => 'Client With Invoices']);
        Invoice::create([
            'invoice_code'   => 'INV-001',
            'client_id'      => $client->id,
            'issue_date'     => now()->toDateString(),
            'due_date'       => now()->addDays(30)->toDateString(),
            'currency'       => 'INR',
            'subtotal'       => 1000,
            'total_amount'   => 1000,
            'balance_due'    => 1000,
            'status'         => 'Sent',
        ]);

        $this->deleteJson("/api/clients/{$client->id}")->assertStatus(422);
        $this->assertNotNull(Client::find($client->id));
    }

    public function test_can_delete_client_with_no_invoices(): void
    {
        Sanctum::actingAs($this->user('super_admin'));
        $client = $this->makeClient(['legal_name' => 'Standalone Client']);

        $this->deleteJson("/api/clients/{$client->id}")->assertOk();
        $this->assertNull(Client::find($client->id));
    }

    public function test_associate_cannot_delete_client(): void
    {
        $partner   = $this->user('partner');
        $associate = $this->user('associate');

        Sanctum::actingAs($partner);
        $client = $this->makeClient();

        Sanctum::actingAs($associate);
        $this->deleteJson("/api/clients/{$client->id}")->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 10. CONTACTS
    // ══════════════════════════════════════════════════════════════════════════

    public function test_add_contact_to_client(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $client = $this->makeClient();

        $this->postJson("/api/clients/{$client->id}/contacts", [
            'name'      => 'John Doe',
            'email'     => 'john@testco.com',
            'phone'     => '1234567890',
            'role_type' => 'Legal',
        ])->assertCreated()->assertJsonFragment(['name' => 'John Doe']);
    }

    public function test_contact_email_must_be_valid(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $client = $this->makeClient();

        $this->postJson("/api/clients/{$client->id}/contacts", [
            'name'  => 'Jane Doe',
            'email' => 'not-a-valid-email',
        ])->assertStatus(422);
    }

    public function test_duplicate_contact_email_rejected(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $client = $this->makeClient();

        $this->postJson("/api/clients/{$client->id}/contacts", [
            'name'  => 'First Person',
            'email' => 'duplicate@testco.com',
        ])->assertCreated();

        $this->postJson("/api/clients/{$client->id}/contacts", [
            'name'  => 'Second Person',
            'email' => 'duplicate@testco.com',
        ])->assertStatus(422);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 11. EDGE CASES
    // ══════════════════════════════════════════════════════════════════════════

    public function test_view_nonexistent_client_returns_404(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $this->getJson('/api/clients/99999')->assertNotFound();
    }

    public function test_client_with_special_characters_in_name(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $name = "O'Reilly & Associates (Pvt.) Ltd.";
        $this->postJson('/api/clients', $this->validClientData([
            'legal_name' => $name,
        ]))->assertCreated()->assertJsonFragment(['legal_name' => $name]);
    }

    public function test_client_stats_returns_correct_structure(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $response = $this->getJson('/api/clients/stats')->assertOk()->json();
        foreach (['total', 'active', 'b2b', 'b2c', 'export', 'unregistered', 'inactive', 'prospect'] as $key) {
            $this->assertArrayHasKey($key, $response, "Missing key: {$key}");
        }
    }

    public function test_company_name_synced_with_legal_name_on_update(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $client = $this->makeClient(['legal_name' => 'Old Name Corp']);

        $this->putJson("/api/clients/{$client->id}", ['legal_name' => 'New Name Corp'])->assertOk();
        $fresh = $client->fresh();
        $this->assertEquals('New Name Corp', $fresh->legal_name);
        $this->assertEquals('New Name Corp', $fresh->company_name);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 12. IMPORT — CSV
    // ══════════════════════════════════════════════════════════════════════════

    public function test_import_csv_creates_clients(): void
    {
        Sanctum::actingAs($this->user('partner'));

        $csv = "legal_name,client_type,nationality,contact_email\n"
             . "CSV Import Corp,organization,India,csv@import.com\n";

        $response = $this->post('/api/clients/import', [
            'file' => $this->csvUpload($csv),
        ])->assertOk()->json();

        $this->assertEquals(1, $response['imported']);
        $this->assertEquals(0, $response['skipped']);
        $this->assertDatabaseHas('clients', ['legal_name' => 'CSV Import Corp']);
    }

    public function test_import_csv_skips_rows_without_legal_name(): void
    {
        Sanctum::actingAs($this->user('partner'));

        $csv = "legal_name,client_type,nationality\n"
             . ",organization,India\n"     // missing legal_name
             . "Valid Corp,organization,India\n";

        $response = $this->post('/api/clients/import', [
            'file' => $this->csvUpload($csv),
        ])->assertOk()->json();

        $this->assertEquals(1, $response['imported']);
        $this->assertEquals(1, $response['skipped']);
    }

    public function test_import_csv_returns_correct_counts(): void
    {
        Sanctum::actingAs($this->user('partner'));

        $csv = "legal_name,client_type,nationality\n"
             . "Corp Alpha,organization,India\n"
             . "Corp Beta,organization,USA\n"
             . "Corp Gamma,individual,India\n";

        $response = $this->post('/api/clients/import', [
            'file' => $this->csvUpload($csv),
        ])->assertOk()->json();

        $this->assertEquals(3, $response['imported']);
        $this->assertEquals(0, $response['skipped']);
        $this->assertEmpty($response['errors']);
    }

    public function test_import_csv_sets_correct_gst_type(): void
    {
        Sanctum::actingAs($this->user('partner'));

        $csv = "legal_name,client_type,nationality,has_gstin\n"
             . "GST Corp,organization,India,true\n"
             . "Foreign Inc,organization,USA,false\n";

        $this->post('/api/clients/import', [
            'file' => $this->csvUpload($csv),
        ])->assertOk();

        $this->assertDatabaseHas('clients', ['legal_name' => 'GST Corp',    'gst_type' => 'B2B']);
        $this->assertDatabaseHas('clients', ['legal_name' => 'Foreign Inc', 'gst_type' => 'Export']);
    }

    public function test_import_csv_accepts_flexible_column_headers(): void
    {
        Sanctum::actingAs($this->user('partner'));

        // Headers with spaces (should be normalized to snake_case)
        $csv = "Legal Name,Client Type,Nationality\n"
             . "Space Header Corp,organization,India\n";

        $response = $this->post('/api/clients/import', [
            'file' => $this->csvUpload($csv),
        ])->assertOk()->json();

        $this->assertEquals(1, $response['imported']);
        $this->assertDatabaseHas('clients', ['legal_name' => 'Space Header Corp']);
    }

    public function test_import_requires_file_or_google_sheet_url(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $this->postJson('/api/clients/import', [])->assertStatus(422);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 13. IMPORT — GOOGLE SHEET
    // ══════════════════════════════════════════════════════════════════════════

    public function test_import_google_sheet_url_creates_clients(): void
    {
        Sanctum::actingAs($this->user('partner'));

        $csvBody = "legal_name,client_type,nationality\n"
                 . "Sheet Corp Alpha,organization,India\n"
                 . "Sheet Corp Beta,individual,USA\n";

        Http::fake([
            'docs.google.com/*' => Http::response($csvBody, 200),
        ]);

        $response = $this->postJson('/api/clients/import', [
            'google_sheet_url' => 'https://docs.google.com/spreadsheets/d/abc123spreadsheet/edit#gid=0',
        ])->assertOk()->json();

        $this->assertEquals(2, $response['imported']);
        $this->assertDatabaseHas('clients', ['legal_name' => 'Sheet Corp Alpha']);
        $this->assertDatabaseHas('clients', ['legal_name' => 'Sheet Corp Beta']);
    }

    public function test_import_google_sheet_inaccessible_returns_422(): void
    {
        Sanctum::actingAs($this->user('partner'));

        Http::fake([
            'docs.google.com/*' => Http::response('', 403),
        ]);

        $this->postJson('/api/clients/import', [
            'google_sheet_url' => 'https://docs.google.com/spreadsheets/d/privatesheetid/edit',
        ])->assertStatus(422);
    }

    public function test_import_invalid_google_sheet_url_returns_422(): void
    {
        Sanctum::actingAs($this->user('partner'));

        $this->postJson('/api/clients/import', [
            'google_sheet_url' => 'https://not-a-google-sheet.com/data',
        ])->assertStatus(422);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 14. IMPORT — AUTHORIZATION
    // ══════════════════════════════════════════════════════════════════════════

    public function test_import_blocked_for_associate(): void
    {
        Sanctum::actingAs($this->user('associate'));

        $csv = "legal_name,client_type\nAssociate Corp,organization\n";
        $this->post('/api/clients/import', [
            'file' => $this->csvUpload($csv),
        ])->assertForbidden();
    }

    public function test_import_blocked_for_unauthenticated(): void
    {
        $csv = "legal_name,client_type\nAnon Corp,organization\n";
        $this->post('/api/clients/import', [
            'file' => $this->csvUpload($csv),
        ])->assertUnauthorized();
    }
}
