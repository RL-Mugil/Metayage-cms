<?php

namespace Tests\Feature;

use App\Models\{Client, Integration, PatentInvoiceIn, Project, User, ZohoSnapshot};
use App\Services\{ZohoClientLinker, ZohoQuoteDraft};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Http};
use Tests\TestCase;

/** A client gets its Zoho customer link when the first quote is sent, by exact name, or after an on-demand lookup. */
class ZohoClientLinkTest extends TestCase
{
    use RefreshDatabase;

    private const SCOPE = 'in:123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 8)->setTime(4, 0));
        Http::preventStrayRequests();
        Integration::create(['slug' => 'zoho', 'name' => 'Zoho Books', 'description' => 'Accounting', 'category' => 'Finance',
            'initials' => 'ZB', 'color' => 'red', 'connected' => true,
            'config' => ['client_id' => 'test', 'client_secret' => encrypt('secret'), 'refresh_token' => encrypt('refresh'), 'organization_id' => '123', 'region' => 'in']]);
        Cache::put('zoho_access_token:in:123', 'fake-token', 3300);
        $this->actingAs(User::factory()->create(['role' => 'finance', 'status' => 'Active']));
    }

    private function client(string $code, string $name, ?string $zohoId = null): Client
    {
        return Client::create(['client_code' => $code, 'client_type' => 'organization', 'legal_name' => $name, 'company_name' => $name,
            'nationality' => 'India', 'status' => 'Active', 'zoho_contact_id' => $zohoId]);
    }

    private function contact(string $id, string $name, string $status = 'active', string $type = 'customer'): array
    {
        $contact = ['contact_id' => $id, 'contact_name' => $name, 'contact_type' => $type, 'status' => $status, 'currency_id' => '789', 'currency_code' => 'INR'];
        ZohoSnapshot::create(['scope' => self::SCOPE, 'resource' => 'contacts', 'remote_id' => $id, 'summary' => json_encode($contact),
            'detail' => json_encode($contact), 'fingerprint' => hash('sha256', $id), 'detail_synced_at' => now()]);

        return $contact;
    }

    private function inQuote(Client $client): PatentInvoiceIn
    {
        $project = Project::create(['project_code' => 'P-'.$client->client_code, 'client_id' => $client->id, 'project_type' => 'Patent',
            'project_name' => 'Invention', 'docket_number' => $client->client_code.'001INPAS']);

        return PatentInvoiceIn::create(['type' => 'quote', 'status' => 'Draft', 'client_id' => $client->id, 'project_id' => $project->id,
            'docket_number' => $project->docket_number, 'invoice_uin' => $project->docket_number.'/Q1', 'service_fees' => 1000, 'discount_amount' => 0,
            'patent_office_fees' => 0, 'other_expenses' => 0, 'igst_amount' => 180, 'invoice_amount' => 1180, 'currency' => 'INR']);
    }

    public function test_sending_the_first_quote_remembers_the_chosen_customer_on_the_client(): void
    {
        $client = $this->client('B37M', 'Luri Energy Private Limited');
        $this->contact('456', 'Luri Energy Private Limited');
        $quote = $this->inQuote($client);
        $draft = app(ZohoQuoteDraft::class)->build($quote->fresh(), self::SCOPE, '456');
        Http::fake(fn ($request) => Http::response(['code' => 0, 'estimate' => ['estimate_id' => '900', 'status' => 'draft',
            'customer_id' => $request['customer_id'], 'reference_number' => $request['reference_number']]], 201));

        $this->postJson("/api/integrations/zoho/quotes/in/{$quote->id}", ['customer_id' => '456', 'version' => $draft['version'], 'review_confirmed' => true])->assertOk();

        $this->assertSame('456', $client->fresh()->zoho_contact_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client_zoho_link', 'subject_id' => $client->id]);
    }

    public function test_a_customer_already_linked_to_another_client_is_not_taken_over(): void
    {
        $this->client('A01M', 'Owner Ltd', '456');
        $other = $this->client('B02M', 'Someone Else Ltd');

        $this->assertFalse(app(ZohoClientLinker::class)->linkChosen($other, '456'));
        $this->assertNull($other->fresh()->zoho_contact_id);
    }

    public function test_name_matching_links_only_unique_active_customers(): void
    {
        $unique = $this->client('C01M', 'Luri Energy Private Limited');
        $suffix = $this->client('C02M', 'Beta Systems Pvt. Ltd.');
        $twice = $this->client('C03M', 'Gamma Works');
        $inactive = $this->client('C04M', 'Delta Labs');
        $already = $this->client('C05M', 'Epsilon Co', '999');
        $this->contact('1', 'LURI ENERGY PVT LTD');
        $this->contact('2', 'Beta Systems Private Limited');
        $this->contact('3', 'Gamma Works');
        $this->contact('4', 'Gamma Works');
        $this->contact('5', 'Delta Labs', 'inactive');
        $this->contact('6', 'Epsilon Co');

        $linked = app(ZohoClientLinker::class)->linkByName(self::SCOPE);

        $this->assertSame(2, $linked);
        $this->assertSame('1', $unique->fresh()->zoho_contact_id);
        $this->assertSame('2', $suffix->fresh()->zoho_contact_id);
        $this->assertNull($twice->fresh()->zoho_contact_id, 'two customers with the same name are ambiguous');
        $this->assertNull($inactive->fresh()->zoho_contact_id);
        $this->assertSame('999', $already->fresh()->zoho_contact_id, 'an existing link is never changed');
    }

    public function test_typing_in_the_customer_search_makes_no_zoho_request(): void
    {
        $quote = $this->inQuote($this->client('B37M', 'Luri Energy Private Limited'));

        $this->getJson("/api/integrations/zoho/quotes/in/{$quote->id}?search=Luri")->assertOk()->assertJsonPath('contacts', []);

        Http::assertNothingSent();
    }

    public function test_the_lookup_button_reads_the_new_customer_from_the_manual_allowance_only(): void
    {
        $quote = $this->inQuote($this->client('B37M', 'Luri Energy Private Limited'));
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/contacts/777')) {
                return Http::response(['code' => 0, 'contact' => ['contact_id' => '777', 'contact_name' => 'Luri Energy Private Limited', 'contact_type' => 'customer',
                    'status' => 'active', 'currency_id' => '789', 'currency_code' => 'INR']]);
            }

            return Http::response(['code' => 0, 'contacts' => [
                ['contact_id' => '777', 'contact_name' => 'Luri Energy Private Limited', 'contact_type' => 'customer', 'status' => 'active'],
                ['contact_id' => '778', 'contact_name' => 'Luri Vendor', 'contact_type' => 'vendor', 'status' => 'active'],
            ], 'page_context' => ['has_more_page' => false]]);
        });

        $response = $this->getJson("/api/integrations/zoho/quotes/in/{$quote->id}?search=Luri&lookup=1")->assertOk();

        $response->assertJsonPath('contacts.0.id', '777');
        Http::assertSentCount(2);   // one list call and one detail call; the vendor is skipped
        $this->assertSame(0, (int) DB::table('zoho_api_budgets')->where('scope', self::SCOPE)->value('automatic_calls'), 'the nightly pool was not used');
    }
}
