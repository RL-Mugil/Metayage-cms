<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DiscussionThread;
use App\Models\Notification;
use App\Models\PatentApplication;
use App\Models\Project;
use App\Models\RenewalDecision;
use App\Models\RenewalSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Client hub: renewal decisions, questions on items, payment details, scorecard, and who may do what. */
class ClientHubTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $admin;

    private User $partner;

    private RenewalSchedule $renewal;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->partner = User::create(['name' => 'Partner', 'email' => 'p@hub.test', 'password' => bcrypt('password'), 'role' => 'partner', 'status' => 'Active']);
        $this->admin = User::create(['name' => 'Client Admin', 'email' => 'ca@hub.test', 'password' => bcrypt('password'), 'role' => 'client_admin', 'status' => 'Active']);
        $this->client = Client::create(['client_code' => '701M', 'client_type' => 'organization', 'legal_name' => 'Helio Labs', 'company_name' => 'Helio Labs',
            'nationality' => 'India', 'status' => 'Active', 'portal_enabled' => true, 'portal_user_id' => $this->admin->id]);
        $app = PatentApplication::create(['client_id' => $this->client->id, 'application_number' => '202141009999', 'jurisdiction' => 'IN', 'legal_status' => 'Granted', 'title' => 'Solar valve']);
        $this->project = Project::create(['client_id' => $this->client->id, 'project_name' => 'Solar valve', 'project_code' => 'PRJ-2026-70001', 'docket_number' => '701M001INREN',
            'project_type' => 'Patent', 'service_code' => 'REN', 'patent_office_code' => 'IN', 'status' => 'In Progress', 'patent_application_id' => $app->id]);
        $this->renewal = RenewalSchedule::create(['patent_application_id' => $app->id, 'renewal_year' => 6, 'due_date' => now()->addDays(40), 'status' => 'Unpaid']);
    }

    public function test_client_admin_sees_the_renewal_and_decides_to_renew(): void
    {
        Sanctum::actingAs($this->admin);
        $hub = $this->getJson('/api/client-hub')->assertOk()->json();
        $this->assertSame($this->renewal->id, $hub['renewals'][0]['renewal_id']);
        $this->assertSame(14, $hub['renewals'][0]['years_left']);
        $this->assertSame('Solar valve', $hub['renewals'][0]['title']);
        $this->assertSame(1, $hub['counts']['renewals']);
        $this->assertTrue($hub['can_decide']);

        $this->postJson("/api/client-hub/renewals/{$this->renewal->id}/decision", ['decision' => 'renew', 'note' => 'Go ahead'])->assertOk();
        $this->assertSame('renew', RenewalDecision::where('renewal_schedule_id', $this->renewal->id)->value('decision'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'renewal_decision', 'subject_id' => $this->renewal->id]);
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('ip_notifications')->where('user_id', $this->partner->id)->where('title', 'Renewal decision')->exists());
        $this->assertSame(0, $this->getJson('/api/client-hub')->json('counts.renewals'));

        // Asking instead opens a discussion on the case.
        $this->postJson("/api/client-hub/renewals/{$this->renewal->id}/decision", ['decision' => 'ask', 'note' => 'Is it worth it?'])->assertOk();
        $this->assertSame(1, RenewalDecision::count());
        $this->assertTrue(DiscussionThread::where('client_id', $this->client->id)->where('project_id', $this->project->id)->exists());
    }

    public function test_a_plain_client_user_cannot_decide_and_nobody_decides_for_another_client(): void
    {
        $viewer = User::create(['name' => 'Viewer', 'email' => 'v@hub.test', 'password' => bcrypt('password'), 'role' => 'client', 'status' => 'Active']);
        $this->client->contacts()->create(['name' => 'Viewer', 'email' => 'v@hub.test']);
        Sanctum::actingAs($viewer);
        $this->assertFalse($this->getJson('/api/client-hub')->assertOk()->json('can_decide'));
        $this->postJson("/api/client-hub/renewals/{$this->renewal->id}/decision", ['decision' => 'renew'])->assertForbidden();

        $other = Client::create(['client_code' => '702M', 'client_type' => 'organization', 'legal_name' => 'Other', 'company_name' => 'Other', 'nationality' => 'India', 'status' => 'Active']);
        Sanctum::actingAs($this->partner);
        $this->postJson("/api/client-hub/renewals/{$this->renewal->id}/decision?client_id={$other->id}", ['decision' => 'renew'])->assertNotFound();
        $this->getJson('/api/client-hub')->assertStatus(422);   // staff must name the client
        $this->getJson("/api/client-hub?client_id={$this->client->id}")->assertOk();

        Sanctum::actingAs(User::create(['name' => 'Analyst', 'email' => 'a@hub.test', 'password' => bcrypt('password'), 'role' => 'associate', 'status' => 'Active']));
        $this->getJson("/api/client-hub?client_id={$this->client->id}")->assertForbidden();
    }

    public function test_question_on_an_item_opens_a_thread_and_tells_the_firm(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/client-hub/comment', ['kind' => 'invoice', 'ref' => '701M001INREN/1', 'project_id' => $this->project->id, 'message' => 'Why this amount?'])
            ->assertCreated()->json('thread_id');
        $thread = DiscussionThread::with('messages')->findOrFail($id);
        $this->assertSame($this->project->id, $thread->project_id);
        $this->assertSame('Why this amount?', $thread->messages->first()->content);
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('ip_notifications')->where('user_id', $this->partner->id)->where('title', 'Client question')->exists());
    }

    public function test_payment_details_finance_edits_client_reads(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/settings/payment-details', ['upi_id' => 'evil@upi'])->assertForbidden();

        Sanctum::actingAs(User::create(['name' => 'Fin', 'email' => 'f@hub.test', 'password' => bcrypt('password'), 'role' => 'finance', 'status' => 'Active']));
        $this->putJson('/api/settings/payment-details', ['bank_name' => 'HDFC', 'upi_id' => 'firm@hdfc'])->assertOk()->assertJson(['upi_id' => 'firm@hdfc']);

        Sanctum::actingAs($this->admin);
        $this->assertSame('HDFC', $this->getJson('/api/client-hub')->json('payment_details.bank_name'));
    }

    public function test_scorecard_downloads_as_pdf(): void
    {
        Sanctum::actingAs($this->admin);
        $response = $this->get('/api/client-hub/scorecard?month='.now()->format('Y-m'));
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
    }
}
