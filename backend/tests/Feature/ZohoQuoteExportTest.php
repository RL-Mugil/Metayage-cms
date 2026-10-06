<?php

namespace Tests\Feature;

use App\Models\{Client, Integration, PatentInvoiceIn, Project, Quotation, User, ZohoQuoteExport, ZohoSnapshot};
use App\Services\{ZohoApiBudget, ZohoBooksService, ZohoCaseMatcher, ZohoInvoiceMirror, ZohoQuoteDraft, ZohoQuoteWriter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Http};
use Tests\TestCase;

class ZohoQuoteExportTest extends TestCase
{
    use RefreshDatabase;

    private Quotation $quote;
    private PatentInvoiceIn $inQuote;

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
        $client = Client::create(['client_code' => 'Z01M', 'client_type' => 'organization', 'legal_name' => 'Quote Client',
            'company_name' => 'Quote Client', 'nationality' => 'India', 'status' => 'Active', 'zoho_contact_id' => '456']);
        $project = Project::create(['project_code' => 'Z01M001INPAT', 'client_id' => $client->id, 'project_type' => 'Patent', 'project_name' => 'Test invention', 'docket_number' => 'Z01M001INPAT']);
        $this->quote = Quotation::create(['client_id' => $client->id, 'project_id' => $project->id, 'quote_code' => 'QUO-2026-00001',
            'valid_until' => now()->addMonth(), 'fee_structure' => 'Fixed Fee', 'subtotal' => 1000, 'tax_amount' => 180, 'total_amount' => 1180, 'currency' => 'INR', 'status' => 'Draft']);
        $this->inQuote = PatentInvoiceIn::create(['type' => 'quote', 'status' => 'Draft', 'client_id' => $client->id, 'project_id' => $project->id,
            'docket_number' => 'Z01M001INPAT', 'invoice_uin' => 'Z01M001INPAT/Q1', 'service_fees' => 1000, 'discount_amount' => 100,
            'patent_office_fees' => 200, 'other_expenses' => 50, 'igst_amount' => 162, 'invoice_amount' => 1312, 'currency' => 'INR',
            'attorney_fees' => 777, 'consultant_fees' => 888, 'referral_fees' => 999]);
        $contact = ['contact_id' => '456', 'contact_name' => 'Quote Client in Books', 'contact_type' => 'customer', 'status' => 'active', 'currency_id' => '789', 'currency_code' => 'INR'];
        ZohoSnapshot::create(['scope' => 'in:123', 'resource' => 'contacts', 'remote_id' => '456', 'summary' => json_encode($contact),
            'detail' => json_encode($contact), 'fingerprint' => str_repeat('a', 64), 'detail_synced_at' => now()]);
    }

    private function url(string $kind = 'standard'): string
    {
        return '/api/integrations/zoho/quotes/' . $kind . '/' . ($kind === 'in' ? $this->inQuote->id : $this->quote->id);
    }

    private function input(string $kind = 'standard'): array
    {
        $quote = $kind === 'in' ? $this->inQuote->fresh() : $this->quote->fresh();
        $draft = app(ZohoQuoteDraft::class)->build($quote, 'in:123', '456');
        return ['customer_id' => '456', 'version' => $draft['version'], 'review_confirmed' => true];
    }

    private function fakeSuccess(): void
    {
        Http::fake(fn ($request) => Http::response(['code' => 0, 'estimate' => [
            'estimate_id' => '900', 'estimate_number' => 'EST-900', 'status' => 'draft',
            'customer_id' => $request['customer_id'], 'reference_number' => $request['reference_number'],
        ]], 201));
    }

    public function test_preview_uses_only_local_data(): void
    {
        $this->getJson($this->url())->assertOk()->assertJsonPath('preview.customer_name', 'Quote Client in Books')
            ->assertJsonPath('preview.payload.line_items.0.rate', 1000)->assertJsonPath('preview.portal_total', 1180);
        Http::assertNothingSent();
    }

    public function test_deleted_export_keeps_portal_quote_but_blocks_another_write(): void
    {
        $this->fakeSuccess();
        $input = $this->input();
        $this->postJson($this->url(), $input)->assertOk();
        app(\App\Services\ZohoRecordLifecycle::class)->delete('in:123', 'estimates', '900', 'webhook');
        $this->assertDatabaseHas('zoho_quote_exports', ['estimate_id' => '900', 'state' => 'deleted']);
        $this->assertNotNull($this->quote->fresh());
        $this->postJson($this->url(), $input)->assertConflict();
        Http::assertSentCount(1);
    }

    public function test_standard_quote_is_created_once_as_draft_with_one_post_and_encrypted_payload(): void
    {
        $this->fakeSuccess();
        $input = $this->input();
        $this->postJson($this->url(), $input)->assertOk()->assertJsonPath('state', 'confirmed');
        $this->postJson($this->url(), $input)->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/estimates?')
            && str_contains($r->url(), 'send=false') && $r['customer_id'] === '456' && $r['line_items'][0]['rate'] === 1000);
        $export = ZohoQuoteExport::firstOrFail();
        $this->assertStringNotContainsString('Professional services', $export->getRawOriginal('payload'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'zoho_quote_created', 'subject_id' => $export->id]);
        $this->assertSame(1, app(ZohoApiBudget::class)->status('in:123')['pools']['write']['used']);
        $this->assertSame(0, app(ZohoApiBudget::class)->status('in:123')['pools']['manual_read']['used']);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_in_quote_sends_discounted_fees_without_internal_costs(): void
    {
        $this->fakeSuccess();
        $this->postJson($this->url('in'), $this->input('in'))->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => array_column($r['line_items'], 'rate') === [900, 200, 50]
            && ! str_contains($r->body(), 'attorney') && ! str_contains($r->body(), 'consultant') && ! str_contains($r->body(), 'referral'));
    }

    public function test_non_finance_roles_cannot_preview_or_write_quotes(): void
    {
        foreach (['client', 'manager', 'associate', 'paralegal', 'galvanizer', 'hr'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'Active']));
            $this->getJson($this->url())->assertForbidden();
            $this->postJson($this->url(), $this->input())->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_invoice_cannot_be_exported_through_quote_endpoint(): void
    {
        $input = $this->input('in');
        $this->inQuote->update(['type' => 'invoice']);
        $this->getJson($this->url('in'))->assertNotFound();
        $this->postJson($this->url('in'), $input)->assertNotFound();
        $this->postJson(str_replace('/quotes/', '/invoices/', $this->url()), $input)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_changed_quote_and_missing_confirmation_are_rejected_before_dispatch(): void
    {
        $input = $this->input();
        $this->quote->update(['subtotal' => 2000, 'tax_amount' => 360, 'total_amount' => 2360]);
        $this->postJson($this->url(), $input)->assertConflict();
        unset($input['review_confirmed']);
        $this->postJson($this->url(), $input)->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_wrong_customer_or_inconsistent_total_is_rejected_without_a_call(): void
    {
        $input = $this->input();
        $this->postJson($this->url(), array_replace($input, ['customer_id' => '987']))->assertUnprocessable();
        $this->quote->update(['total_amount' => 9999]);
        $this->postJson($this->url(), $input)->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_exhausted_write_pool_does_not_spend_manual_reads(): void
    {
        DB::table('zoho_api_budgets')->insert(['scope' => 'in:123', 'budget_date' => now('Asia/Kolkata')->toDateString(), 'calls' => 100, 'write_calls' => 100]);
        $this->postJson($this->url(), $this->input())->assertStatus(429)->assertHeader('Retry-After');
        Http::assertNothingSent();
        $this->assertDatabaseHas('zoho_quote_exports', ['state' => 'rejected']);
        $this->assertSame(100, app(ZohoApiBudget::class)->status('in:123')['pools']['manual_read']['remaining']);
    }

    public function test_timeout_blocks_repeat_send_and_is_reconciled_by_saved_reference(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Simulated timeout'));
        $input = $this->input();
        $this->postJson($this->url(), $input)->assertConflict();
        $this->assertDatabaseHas('zoho_quote_exports', ['state' => 'uncertain']);
        $this->postJson($this->url(), $input)->assertConflict();
        $this->assertSame(1, app(ZohoApiBudget::class)->status('in:123')['used']);
        DB::transaction(fn () => app(ZohoQuoteWriter::class)->reconcile('in:123', [
            'estimate_id' => '900', 'reference_number' => 'IPFLOW-Q-' . $this->quote->id, 'customer_id' => '456',
        ], 'quote'));
        $this->postJson($this->url(), $input)->assertOk()->assertJsonPath('estimate_id', '900');
        $this->assertSame(1, app(ZohoApiBudget::class)->status('in:123')['used']);
    }

    public function test_server_error_does_not_automatically_retry(): void
    {
        Http::fake(['*' => Http::response([], 500)]);
        $this->postJson($this->url(), $this->input())->assertConflict();
        $this->postJson($this->url(), $this->input())->assertConflict();
        Http::assertSentCount(1);
    }

    public function test_accountant_invoice_with_different_number_links_to_exported_quote(): void
    {
        $this->fakeSuccess();
        $this->postJson($this->url(), $this->input())->assertOk();
        $record = ['invoice_id' => '901', 'invoice_number' => 'ACCOUNTANT-2026-1', 'estimate_id' => '900', 'customer_id' => '456', 'total' => 1180];
        DB::transaction(function () use ($record) {
            $matched = $unmatched = 0;
            app(ZohoInvoiceMirror::class)->save($record, 'invoice', 'invoice_id', 'invoice_number', app(ZohoCaseMatcher::class), collect(), collect(), $matched, $unmatched);
            unset($record['estimate_id']);
            app(ZohoInvoiceMirror::class)->save($record, 'invoice', 'invoice_id', 'invoice_number', app(ZohoCaseMatcher::class), collect(), collect(), $matched, $unmatched);
        });
        $this->assertDatabaseHas('zoho_invoices', ['zoho_id' => '901', 'project_id' => $this->quote->project_id, 'client_id' => $this->quote->client_id, 'match_source' => 'export']);
        Http::assertSentCount(1);
    }

    public function test_exported_quotes_cannot_create_local_invoices(): void
    {
        $this->fakeSuccess();
        $this->postJson($this->url(), $this->input())->assertOk();
        $this->quote->update(['status' => 'Sent']);
        $this->postJson('/api/financial/quotations/' . $this->quote->id . '/convert')->assertUnprocessable();
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_exported_in_quote_cannot_create_a_local_invoice(): void
    {
        $this->fakeSuccess();
        $this->postJson($this->url('in'), $this->input('in'))->assertOk();
        $this->postJson('/api/patent-invoices/in/' . $this->inQuote->id . '/convert')->assertUnprocessable();
        $this->assertSame(0, PatentInvoiceIn::where('type', 'invoice')->count());
    }

    public function test_failed_authentication_before_post_is_safe_to_retry_later(): void
    {
        Cache::forget('zoho_access_token:in:123');
        Http::fake(['*' => Http::response(['error' => 'invalid_client'], 400)]);
        $this->postJson($this->url(), $this->input())->assertUnprocessable();
        $this->assertDatabaseHas('zoho_quote_exports', ['state' => 'rejected']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/books/'));
    }

    public function test_write_rate_limit_saves_shared_cooldown_without_repeated_posts(): void
    {
        Http::fake(['*' => Http::response(['code' => 44], 429, ['Retry-After' => '90'])]);
        $this->postJson($this->url(), $this->input())->assertStatus(429)->assertHeader('Retry-After', '90');
        $this->postJson($this->url(), $this->input())->assertStatus(429);
        Http::assertSentCount(1);
        $this->assertSame(1, app(ZohoApiBudget::class)->status('in:123')['pools']['write']['used']);
    }

    public function test_customer_currency_mismatch_is_rejected_locally(): void
    {
        $input = $this->input();
        $this->quote->update(['currency' => 'USD']);
        $this->postJson($this->url(), $input)->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_reconciliation_does_not_link_another_customer_or_organization(): void
    {
        $this->fakeSuccess();
        $this->postJson($this->url(), $this->input())->assertOk();
        $record = ['estimate_id' => '900', 'customer_id' => '999', 'reference_number' => 'IPFLOW-Q-' . $this->quote->id];
        DB::transaction(function () use ($record) {
            $writer = app(ZohoQuoteWriter::class);
            $this->assertNull($writer->reconcile('in:123', $record, 'invoice'));
            $this->assertNull($writer->reconcile('in:999', array_replace($record, ['customer_id' => '456']), 'invoice'));
        });
    }
}
