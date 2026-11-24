<?php

namespace Tests\Feature;

use App\Mail\NotificationCopyMail;
use App\Models\Client;
use App\Models\DocketDeadline;
use App\Models\Notification;
use App\Models\PatentInvoiceIn;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Team tab, saved views, notification channels, and search scoping. */
class TeamToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    private User $engineer;

    private Client $client;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->partner = User::create(['name' => 'Partner', 'email' => 'p@team.test', 'password' => bcrypt('password'), 'role' => 'partner', 'status' => 'Active']);
        $this->engineer = User::create(['name' => 'Engineer', 'email' => 'e@team.test', 'password' => bcrypt('password'), 'role' => 'associate', 'status' => 'Active']);
        $this->client = Client::create(['client_code' => '801M', 'client_type' => 'organization', 'legal_name' => 'Quark Ltd', 'company_name' => 'Quark Ltd', 'nationality' => 'India', 'status' => 'Active', 'portal_enabled' => true]);
        $this->project = Project::create(['client_id' => $this->client->id, 'project_name' => 'Quark gate', 'project_code' => 'PRJ-2026-80001', 'docket_number' => '801M001INCPT',
            'project_type' => 'Patent', 'service_code' => 'CPT', 'patent_office_code' => 'IN', 'status' => 'In Progress',
            'assigned_manager_id' => $this->partner->id, 'patent_engineer_id' => $this->engineer->id]);
        DocketDeadline::create(['project_id' => $this->project->id, 'title' => 'Reply to FER', 'due_date' => now()->subDays(2)->toDateString(), 'status' => 'Open']);
        DocketDeadline::create(['project_id' => $this->project->id, 'title' => 'Form 18', 'due_date' => now()->addDays(10)->toDateString(), 'status' => 'Open']);
    }

    public function test_team_tab_workload_heatmap_and_leaderboard_switch(): void
    {
        Sanctum::actingAs($this->partner);
        $team = $this->getJson('/api/dashboard/team')->assertOk()->json();
        $rows = collect($team['workload'])->keyBy('user_id');
        $this->assertSame(1, $rows[$this->partner->id]['pcm']);
        $this->assertSame(1, $rows[$this->engineer->id]['pr']);
        $this->assertSame(1, $rows[$this->engineer->id]['overdue']);
        $this->assertSame(1, $team['heatmap']['days'][now()->addDays(10)->toDateString()]);
        $this->assertSame(1, $team['heatmap']['overdue']);
        $this->assertNull($team['leaderboard']);

        DB::table('system_settings')->insert(['key' => 'leaderboard_enabled', 'value' => '1']);
        $this->assertIsArray($this->getJson('/api/dashboard/team')->json('leaderboard'));

        $cases = $this->getJson("/api/dashboard/team/{$this->engineer->id}/cases")->assertOk()->json();
        $this->assertSame('PR', $cases[0]['as']);
        $day = $this->getJson('/api/dashboard/deadlines?bucket=day&date='.now()->addDays(10)->toDateString())->assertOk()->json();
        $this->assertSame('Form 18', $day[0]['title']);

        Sanctum::actingAs(User::create(['name' => 'HR', 'email' => 'hr@team.test', 'password' => bcrypt('password'), 'role' => 'hr', 'status' => 'Active']));
        $this->getJson('/api/dashboard/team')->assertForbidden();
    }

    public function test_saved_views_belong_to_their_owner(): void
    {
        Sanctum::actingAs($this->partner);
        $id = $this->postJson('/api/saved-views', ['page' => 'patent_portfolio', 'name' => 'My FERs', 'filters' => ['search' => 'FER', 'office' => 'IN']])->assertCreated()->json('id');
        $this->postJson('/api/saved-views', ['page' => 'patent_portfolio', 'name' => 'My FERs', 'filters' => ['search' => 'HRG']])->assertCreated();
        $views = $this->getJson('/api/saved-views?page=patent_portfolio')->assertOk()->json();
        $this->assertCount(1, $views);
        $this->assertSame('HRG', $views[0]['filters']['search']);

        Sanctum::actingAs($this->engineer);
        $this->assertSame([], $this->getJson('/api/saved-views?page=patent_portfolio')->json());
        $this->deleteJson("/api/saved-views/{$id}")->assertNotFound();
    }

    public function test_notification_channels_quiet_and_email(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->partner);
        $this->putJson('/api/settings/notification-channels', ['channels' => ['payments' => 'email', 'deadlines' => 'off']])->assertOk()
            ->assertJsonPath('channels.payments', 'email')->assertJsonPath('channels.tasks', 'in_app');
        // Saving the old toggles keeps the channel choices.
        $this->putJson('/api/settings/notifications', ['taskAssigned' => true, 'deadlineEmail' => true, 'paymentReceived' => true, 'pushNotif' => false, 'weeklyDigest' => true, 'monthlyReport' => true])->assertOk();
        $this->assertSame('email', $this->partner->fresh()->notification_prefs['channels']['payments']);

        $pay = Notification::create(['user_id' => $this->partner->id, 'title' => 'Payment received', 'message' => 'Invoice 801M001INCPT/1 paid', 'is_read' => false]);
        $deadline = Notification::create(['user_id' => $this->partner->id, 'title' => 'Deadline in 3 days', 'message' => 'Reply to FER', 'is_read' => false]);
        $task = Notification::create(['user_id' => $this->partner->id, 'title' => 'Task assigned', 'message' => 'Draft claims', 'is_read' => false]);

        $this->assertTrue($pay->fresh()->is_read);
        $this->assertTrue($deadline->fresh()->is_read);
        $this->assertFalse($task->fresh()->is_read);
        Mail::assertSent(NotificationCopyMail::class, 1);
        Mail::assertSent(NotificationCopyMail::class, fn ($m) => $m->hasTo('p@team.test') && $m->heading === 'Payment received');
    }

    public function test_search_is_scoped_for_portal_roles_and_finds_invoices(): void
    {
        $other = Client::create(['client_code' => '802M', 'client_type' => 'organization', 'legal_name' => 'Quark Rival', 'company_name' => 'Quark Rival', 'nationality' => 'India', 'status' => 'Active']);
        Project::create(['client_id' => $other->id, 'project_name' => 'Quark rival', 'project_code' => 'PRJ-2026-80002', 'docket_number' => '802M001INCPT', 'project_type' => 'Patent', 'status' => 'In Progress']);
        PatentInvoiceIn::create(['type' => 'invoice', 'status' => 'Sent', 'source' => 'zoho', 'client_id' => $this->client->id, 'invoice_uin' => '801M001INCPT/1',
            'invoice_amount' => 5000, 'total_bcy' => 5000, 'balance_due' => 5000, 'invoice_date' => now()->toDateString(), 'currency' => 'INR']);

        $finance = User::create(['name' => 'Client Fin', 'email' => 'cf@team.test', 'password' => bcrypt('password'), 'role' => 'client_finance', 'status' => 'Active']);
        $this->client->update(['portal_user_id' => $finance->id]);
        Sanctum::actingAs($finance);
        $results = collect($this->getJson('/api/search?q=Quark')->assertOk()->json('results'));
        $this->assertSame([], $results->where('type', 'client')->values()->all());
        $this->assertSame(['801M001INCPT'], $results->where('type', 'project')->pluck('title')->map(fn ($t) => $t === 'PRJ-2026-80001' ? '801M001INCPT' : $t)->values()->all());

        $invoices = collect($this->getJson('/api/search?q=801M001')->json('results'))->where('type', 'invoice');
        $this->assertSame(['801M001INCPT/1'], $invoices->pluck('title')->values()->all());
    }
}
