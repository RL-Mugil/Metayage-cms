<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Integration;
use App\Models\PatentInvoiceIn;
use App\Models\Project;
use App\Models\ZohoInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncZohoBooksTest extends TestCase
{
    use RefreshDatabase;

    private function configureZoho(): void
    {
        Integration::create([
            'slug' => 'zoho', 'name' => 'Zoho Books', 'description' => 'Accounting',
            'category' => 'Finance', 'initials' => 'ZB', 'color' => 'bg-red-600',
            'connected' => true,
            'config' => [
                'client_id'       => 'test-client-id',
                'client_secret'   => encrypt('test-secret'),
                'refresh_token'   => encrypt('test-refresh'),
                'organization_id' => '924754718',
                'region'          => 'in',
                'api_key'         => encrypt('test-refresh'),
            ],
        ]);
    }

    public function test_sync_matches_invoice_by_exact_uin(): void
    {
        $this->configureZoho();

        $client = Client::create(['client_code' => '807M', 'client_type' => 'organization', 'legal_name' => 'Blinkcharging', 'company_name' => 'Blinkcharging', 'nationality' => 'India', 'status' => 'Active']);
        $project = Project::create(['project_code' => '807M004INFFP', 'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'Test Invention', 'docket_number' => '807M004INFFP']);
        PatentInvoiceIn::create([
            'type' => 'invoice', 'status' => 'Sent', 'project_id' => $project->id, 'client_id' => $client->id,
            'docket_number' => '807M004INFFP', 'invoice_uin' => '807M004INFFP/1',
        ]);

        Http::fake([
            'https://accounts.zoho.in/oauth/v2/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200),
            'https://www.zohoapis.in/books/v3/invoices*' => Http::response([
                'invoices' => [[
                    'invoice_id' => 'zid-1', 'invoice_number' => '807M004INFFP/1', 'status' => 'overdue',
                    'total' => 8000, 'balance' => 8000, 'currency_code' => 'INR', 'date' => '2026-07-30',
                    'customer_id' => '164863000000183911',
                ]],
                'page_context' => ['has_more_page' => false],
            ]),
            'https://www.zohoapis.in/books/v3/estimates*' => Http::response(['estimates' => [], 'page_context' => ['has_more_page' => false]]),
        ]);

        $this->artisan('zoho:sync')->assertExitCode(0);

        $this->assertDatabaseHas('zoho_invoices', [
            'zoho_id' => 'zid-1', 'zoho_type' => 'invoice',
            'client_id' => $client->id, 'project_id' => $project->id,
            'number' => '807M004INFFP/1', 'match_source' => 'uin',
        ]);

        $log = \DB::table('integration_logs')->where('slug', 'zoho')->where('event_type', 'sync')->first();
        $this->assertNotNull($log);
        $this->assertSame('ok', $log->status);
    }

    public function test_sync_falls_back_to_bare_docket_when_no_uin_row_exists(): void
    {
        $this->configureZoho();

        $client = Client::create(['client_code' => '601M', 'client_type' => 'organization', 'legal_name' => 'KS Knitfabs', 'company_name' => 'KS Knitfabs', 'nationality' => 'India', 'status' => 'Active']);
        $project = Project::create(['project_code' => '601M001IN27F', 'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'Another Invention', 'docket_number' => '601M001IN27F']);
        // No PatentInvoiceIn row at all — Zoho is the only place this quote was ever raised.

        Http::fake([
            'https://accounts.zoho.in/oauth/v2/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200),
            'https://www.zohoapis.in/books/v3/invoices*' => Http::response(['invoices' => [], 'page_context' => ['has_more_page' => false]]),
            'https://www.zohoapis.in/books/v3/estimates*' => Http::response([
                'estimates' => [[
                    'estimate_id' => 'zid-2', 'estimate_number' => '601M001IN27F', 'status' => 'sent',
                    'total' => 3540, 'currency_code' => 'INR', 'date' => '2026-07-30',
                ]],
                'page_context' => ['has_more_page' => false],
            ]),
        ]);

        $this->artisan('zoho:sync')->assertExitCode(0);

        $this->assertDatabaseHas('zoho_invoices', [
            'zoho_id' => 'zid-2', 'zoho_type' => 'quote',
            'client_id' => $client->id, 'project_id' => $project->id,
            'number' => '601M001IN27F', 'match_source' => 'docket',
        ]);
    }

    public function test_sync_skips_records_with_no_matching_case(): void
    {
        $this->configureZoho();

        Http::fake([
            'https://accounts.zoho.in/oauth/v2/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200),
            'https://www.zohoapis.in/books/v3/invoices*' => Http::response([
                'invoices' => [['invoice_id' => 'zid-x', 'invoice_number' => 'NOMATCH123', 'status' => 'paid', 'total' => 100, 'currency_code' => 'INR']],
                'page_context' => ['has_more_page' => false],
            ]),
            'https://www.zohoapis.in/books/v3/estimates*' => Http::response(['estimates' => [], 'page_context' => ['has_more_page' => false]]),
        ]);

        $this->artisan('zoho:sync')->assertExitCode(0);
        $this->assertDatabaseCount('zoho_invoices', 0);
    }

    public function test_sync_skips_when_not_configured(): void
    {
        $this->artisan('zoho:sync')->assertExitCode(0);
        $this->assertDatabaseCount('zoho_invoices', 0);
    }
}
