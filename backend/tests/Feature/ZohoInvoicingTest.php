<?php

namespace Tests\Feature;

use App\Models\AdvanceQuoteLink;
use App\Models\Client;
use App\Models\Integration;
use App\Models\PatentInvoiceIn;
use App\Models\User;
use App\Models\ZohoQuoteExport;
use App\Models\ZohoSnapshot;
use App\Services\RenewalQuotes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** An earmarked advance applied in Zoho to the invoice made from its quote; the renewal quote switch. */
class ZohoInvoicingTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private AdvanceQuoteLink $link;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Integration::create(['slug' => 'zoho', 'name' => 'Zoho Books', 'description' => 'Accounting', 'category' => 'Finance',
            'initials' => 'ZB', 'color' => 'red', 'connected' => true,
            'config' => ['client_id' => 'test', 'client_secret' => encrypt('secret'), 'refresh_token' => encrypt('refresh'), 'organization_id' => '123', 'region' => 'in']]);
        Cache::put('zoho_access_token:in:123', 'fake-token', 86400);
        $this->client = Client::create(['client_code' => '445M', 'client_type' => 'organization', 'legal_name' => 'Sustainable Reference Analytics',
            'company_name' => 'SRA', 'nationality' => 'India', 'status' => 'Active', 'zoho_contact_id' => '900']);
        $quote = PatentInvoiceIn::create(['type' => 'quote', 'status' => 'Accepted', 'source' => 'manual', 'client_id' => $this->client->id, 'invoice_uin' => '445M010INPRV500',
            'invoice_date' => '2026-11-20', 'invoice_amount' => 35400, 'total_bcy' => 35400, 'exchange_rate' => 1, 'payment_status' => 'Pending', 'currency' => 'INR']);
        ZohoSnapshot::create(['scope' => 'in:123', 'resource' => 'customerpayments', 'remote_id' => '377', 'summary' => '{}', 'fingerprint' => 'p',
            'detail' => json_encode(['payment_id' => '377', 'payment_number' => 'P377', 'date' => '2026-08-25', 'amount' => 32400, 'unused_amount' => 3600,
                'exchange_rate' => 1, 'currency_code' => 'INR', 'customer_id' => '900', 'invoices' => []])]);
        $this->link = AdvanceQuoteLink::create(['zoho_payment_id' => '377', 'payment_number' => 'P377', 'client_id' => $this->client->id,
            'patent_invoice_in_id' => $quote->id, 'amount' => 3600]);
    }

    private function accounts(): User
    {
        return User::create(['name' => 'Accounts', 'email' => 'f@zinv.test', 'password' => bcrypt('password'), 'role' => 'finance', 'status' => 'Active']);
    }

    public function test_an_earmarked_advance_is_applied_in_zoho_once_the_quote_is_invoiced(): void
    {
        Sanctum::actingAs($this->accounts());
        $this->postJson("/api/advance-links/{$this->link->id}/apply")->assertStatus(422);   // not invoiced yet

        ZohoQuoteExport::create(['scope' => 'in:123', 'source_type' => 'in', 'source_id' => $this->link->patent_invoice_in_id, 'client_id' => $this->client->id,
            'customer_id' => '900', 'reference' => 'IPFLOW-INQ-1', 'estimate_id' => '55', 'invoice_id' => '66', 'state' => 'confirmed', 'conversion_state' => 'converted', 'payload' => '{}']);
        Http::fake(function ($request) {
            $url = $request->url();
            if ($request->method() === 'POST' && str_contains($url, '/invoices/66/credits')) {
                return Http::response(['code' => 0, 'message' => 'Credits have been applied to the invoice.']);
            }
            if (str_contains($url, '/customerpayments/377?')) {
                return Http::response(['payment' => ['payment_id' => '377', 'payment_number' => 'P377', 'date' => '2026-08-25', 'amount' => 32400, 'unused_amount' => 0,
                    'customer_id' => '900', 'invoices' => [['invoice_id' => '66', 'amount_applied' => 3600]]]]);
            }
            if (str_contains($url, '/invoices/66?')) {
                return Http::response(['invoice' => ['invoice_id' => '66', 'invoice_number' => '445M010INPRV500', 'customer_id' => '900', 'total' => 35400, 'balance' => 31800, 'status' => 'partially_paid']]);
            }

            return Http::response([], 500);
        });

        $this->postJson("/api/advance-links/{$this->link->id}/apply")->assertOk();

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/invoices/66/credits')
            && $r['invoice_payments'][0]['payment_id'] === '377' && (float) $r['invoice_payments'][0]['amount_applied'] === 3600.0);
        $this->assertNotNull($this->link->fresh()->applied_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'advance_applied_in_zoho']);
        $this->postJson("/api/advance-links/{$this->link->id}/apply")->assertStatus(422);   // only once
        $this->deleteJson("/api/advance-links/{$this->link->id}")->assertStatus(422);        // applied: no unlinking
    }

    public function test_only_accounts_apply_advances_and_switch_renewal_quotes(): void
    {
        Sanctum::actingAs(User::create(['name' => 'Manager', 'email' => 'm@zinv.test', 'password' => bcrypt('password'), 'role' => 'manager', 'status' => 'Active']));
        $this->postJson("/api/advance-links/{$this->link->id}/apply")->assertForbidden();
        $this->putJson('/api/finance/controls/renewal-quotes', ['enabled' => true, 'days' => 90])->assertForbidden();

        $this->assertFalse(RenewalQuotes::settings()['enabled']);   // off by default
        Sanctum::actingAs($this->accounts());
        $this->putJson('/api/finance/controls/renewal-quotes', ['enabled' => true, 'days' => 60])->assertOk();
        $this->assertSame(['enabled' => true, 'days' => 60], RenewalQuotes::settings());
        Http::assertNothingSent();
    }
}
