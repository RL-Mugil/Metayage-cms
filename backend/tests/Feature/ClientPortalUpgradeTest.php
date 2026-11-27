<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Client;
use App\Models\PatentInvoiceIn;
use App\Models\Project;
use App\Models\RegisterPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Client portal: quotes in Approvals, client notifications, discussions with the firm's people, native billing. */
class ClientPortalUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $admin;

    private User $pcm;

    private User $accounts;

    private User $outsider;

    private Project $case;

    protected function setUp(): void
    {
        parent::setUp();
        $u = fn (string $role, string $email, string $name) => User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password'), 'role' => $role, 'status' => 'Active']);
        $this->admin = $u('client_admin', 'anusha@client.test', 'Anusha');
        $this->pcm = $u('manager', 'pcm@firm.test', 'PCM');
        $this->accounts = $u('finance', 'acc@firm.test', 'Accounts');
        $this->outsider = $u('associate', 'other@firm.test', 'Other engineer');
        $this->client = Client::create(['client_code' => '940M', 'client_type' => 'organization', 'legal_name' => 'Niramai', 'company_name' => 'Niramai',
            'nationality' => 'India', 'status' => 'Active', 'portal_enabled' => true, 'portal_user_id' => $this->admin->id]);
        $this->case = Project::create(['client_id' => $this->client->id, 'project_name' => 'Thermal', 'project_code' => 'PRJ-2026-94001', 'docket_number' => '940M001INPAT',
            'project_type' => 'Patent', 'service_code' => 'PAT', 'patent_office_code' => 'IN', 'status' => 'In Progress', 'assigned_manager_id' => $this->pcm->id]);
    }

    private function quote(string $status = 'Sent'): PatentInvoiceIn
    {
        return PatentInvoiceIn::create(['type' => 'quote', 'status' => $status, 'source' => 'manual', 'client_id' => $this->client->id, 'project_id' => $this->case->id,
            'docket_number' => '940M001INPAT', 'invoice_uin' => '940M001INPAT/Q1', 'invoice_date' => now()->toDateString(), 'service_fees' => 25000,
            'invoice_amount' => 29500, 'total_bcy' => 29500, 'exchange_rate' => 1, 'currency' => 'INR', 'created_by_id' => $this->accounts->id]);
    }

    private function notified(User $user, string $like): bool
    {
        return DB::table('ip_notifications')->where('user_id', $user->id)->where('title', 'like', $like)->exists();
    }

    public function test_a_sent_quote_waits_in_approvals_and_approving_it_there_accepts_the_quote(): void
    {
        $quote = $this->quote();
        $approval = Approval::where('subject_type', 'PatentInvoiceIn')->where('subject_id', $quote->id)->first();
        $this->assertNotNull($approval);
        $this->assertSame('Pending', $approval->status);
        $this->assertTrue($this->notified($this->admin, 'Quote % to approve'));   // the client is told

        Sanctum::actingAs($this->admin);
        $list = $this->getJson('/api/approvals')->assertOk()->json('data');
        $this->assertSame($quote->id, $list[0]['quote_id']);
        $this->assertEquals(29500, $list[0]['amount']);

        $this->postJson('/api/approvals/resolve', ['type' => 'Client', 'id' => $approval->id, 'action' => 'Approved'])->assertOk();
        $this->assertSame('Accepted', $quote->fresh()->status);
        $this->assertSame('Approved', $approval->fresh()->status);
        $this->assertTrue($this->notified($this->pcm, 'Client approved quote%'));
    }

    public function test_approving_from_the_dashboard_resolves_the_approval_too(): void
    {
        $quote = $this->quote();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/pending-payments/{$quote->id}/approve-quote")->assertOk();
        $this->assertSame('Approved', Approval::where('subject_id', $quote->id)->value('status'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'client_approve_quote', 'subject_id' => $quote->id]);
    }

    public function test_a_quote_cancelled_by_the_firm_closes_its_approval(): void
    {
        $quote = $this->quote();
        $quote->update(['status' => 'Cancelled']);
        $this->assertSame('Cancelled', Approval::where('subject_id', $quote->id)->value('status'));
    }

    public function test_clients_are_told_about_new_invoices_and_payments(): void
    {
        $invoice = PatentInvoiceIn::create(['type' => 'invoice', 'status' => 'Sent', 'source' => 'manual', 'client_id' => $this->client->id, 'invoice_uin' => '940M001INPAT/1',
            'invoice_date' => now()->toDateString(), 'invoice_amount' => 29500, 'total_bcy' => 29500, 'balance_due' => 29500, 'exchange_rate' => 1, 'currency' => 'INR', 'payment_status' => 'Pending']);
        $this->assertTrue($this->notified($this->admin, 'New invoice%'));
        RegisterPayment::create(['patent_invoice_in_id' => $invoice->id, 'client_id' => $this->client->id, 'zoho_payment_id' => 'p-1', 'payment_date' => now()->toDateString(),
            'amount' => 29500, 'currency' => 'INR', 'exchange_rate' => 1, 'amount_bcy' => 29500, 'source' => 'zoho', 'kind' => 'cash', 'estimated' => false]);
        $this->assertTrue($this->notified($this->admin, 'Payment received'));
        // An old invoice imported later stays quiet.
        PatentInvoiceIn::create(['type' => 'invoice', 'status' => 'Sent', 'source' => 'zoho', 'client_id' => $this->client->id, 'invoice_uin' => '940M000/OLD',
            'invoice_date' => now()->subYear()->toDateString(), 'invoice_amount' => 100, 'total_bcy' => 100, 'balance_due' => 0, 'exchange_rate' => 1, 'currency' => 'INR', 'payment_status' => 'Confirmed']);
        $this->assertFalse($this->notified($this->admin, 'New invoice 940M000/OLD'));
    }

    public function test_a_client_starts_a_discussion_with_the_people_on_their_account_and_can_dm_only_them(): void
    {
        Sanctum::actingAs($this->admin);
        $thread = $this->postJson('/api/discussions', ['title' => 'Filing timeline', 'message' => 'When will it be filed?'])->assertCreated()->json();
        $members = DB::table('discussion_participants')->where('thread_id', $thread['id'])->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($this->pcm->id, $members);
        $this->assertContains($this->accounts->id, $members);
        $this->assertNotContains($this->outsider->id, $members);   // not on this client
        $this->assertTrue($this->notified($this->pcm, 'New discussion%'));

        $contacts = collect($this->getJson('/api/dm/contacts')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($this->pcm->id, $contacts);
        $this->assertNotContains($this->outsider->id, $contacts);
        $this->postJson("/api/dm/open/{$this->pcm->id}")->assertSuccessful();
        $this->postJson("/api/dm/open/{$this->outsider->id}")->assertForbidden();
    }

    public function test_my_billing_shows_what_the_client_owes_from_the_register(): void
    {
        $this->quote();
        PatentInvoiceIn::create(['type' => 'invoice', 'status' => 'Sent', 'source' => 'manual', 'client_id' => $this->client->id, 'invoice_uin' => '940M001INPAT/2',
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(10)->toDateString(), 'invoice_amount' => 11800, 'total_bcy' => 11800, 'balance_due' => 11800,
            'exchange_rate' => 1, 'currency' => 'INR', 'payment_status' => 'Pending']);
        Sanctum::actingAs($this->admin);
        $b = $this->getJson('/api/my-billing')->assertOk()->json();
        $this->assertEquals(11800, $b['summary']['unpaid_invoices']);
        $this->assertEquals(29500, $b['summary']['quoted']);
        $this->assertCount(1, $b['unpaid']);
        $this->assertCount(1, $b['proformas']);

        Sanctum::actingAs($this->pcm);
        $this->getJson('/api/my-billing')->assertForbidden();   // clients only
    }
}
