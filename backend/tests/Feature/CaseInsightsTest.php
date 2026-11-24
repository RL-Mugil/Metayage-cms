<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\DocketDeadline;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\User;
use App\Services\CaseInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Case health, stuck stages, document checklist, journey and the weekly change feed. */
class CaseInsightsTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->partner = User::create(['name' => 'Partner', 'email' => 'p@ins.test', 'password' => bcrypt('password'), 'role' => 'partner', 'status' => 'Active']);
        $client = Client::create(['client_code' => '601M', 'client_type' => 'organization', 'legal_name' => 'Nova Bio', 'company_name' => 'Nova Bio', 'nationality' => 'India', 'status' => 'Active']);
        $this->project = Project::create(['client_id' => $client->id, 'project_name' => 'Sensor', 'project_code' => 'PRJ-2026-60001', 'docket_number' => '601M001INCPT',
            'project_type' => 'Patent', 'service_code' => 'CPT', 'patent_office_code' => 'IN', 'status' => 'In Progress']);
        ProjectStage::create(['project_id' => $this->project->id, 'stage_name' => 'Drafting', 'status' => 'Completed', 'sequence_order' => 1, 'duration_days' => 10, 'actual_end_at' => now()->subDays(30)]);
        ProjectStage::create(['project_id' => $this->project->id, 'stage_name' => 'FER Response', 'status' => 'In Progress', 'sequence_order' => 2, 'duration_days' => 10, 'actual_start_at' => now()->subDays(25)]);
        DocketDeadline::create(['project_id' => $this->project->id, 'title' => 'Reply to FER', 'due_date' => now()->addDays(5)->toDateString(), 'status' => 'Open']);
        Document::create(['project_id' => $this->project->id, 'client_id' => $client->id, 'file_name' => 'Sensor IDF v2.docx', 'category' => 'Patents', 'storage_path' => 'x/1', 'file_type' => 'docx', 'file_size' => 10, 'uploaded_by_id' => $this->partner->id]);
        Document::create(['project_id' => $this->project->id, 'client_id' => $client->id, 'file_name' => 'Form-26 signed.pdf', 'category' => 'Forms', 'storage_path' => 'x/2', 'file_type' => 'pdf', 'file_size' => 10, 'uploaded_by_id' => $this->partner->id]);
    }

    public function test_health_counts_the_near_deadline_the_overrun_stage_and_missing_documents(): void
    {
        $health = app(CaseInsights::class)->healthMany(collect([$this->project]))[$this->project->id];

        // 100 - 25 (deadline in 5 days) - 25 (25 days in a 10-day stage) - 15 (missing docs, capped)
        $this->assertSame(35, $health['score']);
        $this->assertSame('risk', $health['level']);
        $this->assertContains('Deadline in 5 days', $health['reasons']);
    }

    public function test_checklist_matches_file_names_and_skips_non_filing_services(): void
    {
        $list = collect(app(CaseInsights::class)->checklist($this->project))->keyBy('key');
        $this->assertTrue($list['idf']['present']);
        $this->assertTrue($list['poa']['present']);
        $this->assertFalse($list['form1']['present']);
        $this->assertTrue($list->has('form5'));   // complete specification needs inventorship

        $search = $this->project->replicate()->fill(['service_code' => 'PAS']);
        $this->assertSame([], app(CaseInsights::class)->checklist($search));
    }

    public function test_case_and_portfolio_endpoints(): void
    {
        AuditLog::create(['user_id' => $this->partner->id, 'action' => 'stage_change', 'subject_type' => 'Project', 'subject_id' => $this->project->id, 'metadata' => ['new_stage' => 'FER Response']]);
        Sanctum::actingAs($this->partner);

        $case = $this->getJson("/api/projects/{$this->project->id}/insights")->assertOk()->json();
        $this->assertSame(['Drafting', 'FER Response'], array_column($case['journey']['stages'], 'name'));
        $this->assertSame('Reply to FER', $case['journey']['upcoming'][0]['title']);

        $portfolio = $this->getJson('/api/patent-portfolio/insights')->assertOk()->json();
        $this->assertSame(15, $portfolio['stuck'][0]['over']);
        $this->assertSame('601M001INCPT', $portfolio['stuck'][0]['docket_number']);
        $texts = array_column($portfolio['changes'], 'text');
        $this->assertContains('Moved to FER Response', $texts);
        $this->assertTrue(collect($texts)->contains(fn ($t) => str_starts_with($t, 'Deadline added: Reply to FER')));
        $this->assertArrayHasKey((string) $this->project->id, $portfolio['health']);
    }

    public function test_an_associate_not_on_the_case_cannot_read_its_insights(): void
    {
        Sanctum::actingAs(User::create(['name' => 'Analyst', 'email' => 'a@ins.test', 'password' => bcrypt('password'), 'role' => 'associate', 'status' => 'Active']));
        $this->getJson("/api/projects/{$this->project->id}/insights")->assertForbidden();
        $this->assertSame([], $this->getJson('/api/patent-portfolio/insights')->assertOk()->json('stuck'));
    }
}
