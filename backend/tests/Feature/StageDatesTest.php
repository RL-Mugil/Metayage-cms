<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Work-stage dates: recorded like docket events, auto-logged without overwriting, back-logged. */
class StageDatesTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::create(['name' => 'Partner', 'email' => 'partner@stages.test', 'password' => bcrypt('password'), 'role' => 'partner', 'status' => 'Active']));
        $this->client = Client::create([
            'client_code' => '402Y', 'client_type' => 'organization', 'legal_name' => 'Aivar', 'company_name' => 'Aivar',
            'nationality' => 'United States', 'status' => 'Active',
        ]);
    }

    private function createCase(string $service = 'PRD', string $office = 'US', string $seq = '001', array $extra = []): Project
    {
        $docket = "402Y{$seq}{$office}{$service}";
        $id = $this->postJson('/api/projects', array_merge([
            'record_mode' => 'existing', 'project_code' => $docket, 'docket_number' => $docket,
            'client_id' => $this->client->id, 'project_name' => "Case {$docket}", 'project_type' => 'Patent',
            'patent_office_code' => $office, 'service_code' => $service,
        ], $extra))->assertCreated()->json('id');

        return Project::findOrFail($id);
    }

    private function stage(Project $project, string $name)
    {
        return $project->stages()->where('stage_name', $name)->firstOrFail();
    }

    public function test_prd_lists_drawings_before_the_specification(): void
    {
        $names = $this->createCase()->stages()->orderBy('sequence_order')->pluck('stage_name')->all();
        $this->assertLessThan(array_search('Provisional specification drafted', $names, true), array_search('Drawings prepared', $names, true));
    }

    public function test_moving_stages_logs_today_only_where_no_date_is_recorded(): void
    {
        $case = $this->createCase();
        $first = $case->stages()->orderBy('sequence_order')->first();

        // A date recorded by hand on the first stage ...
        $this->postJson("/api/projects/{$case->id}/stage-events", ['stage_id' => $first->id, 'event' => 'completed', 'event_date' => '2026-10-01', 'notes' => 'Kick-off call'])->assertCreated();
        $this->assertSame('2026-10-01', $first->fresh()->actual_end_at->toDateString());
        $this->assertSame('manual', $first->fresh()->end_source);

        // ... survives moving stages from the case list (stage picker).
        $this->postJson("/api/projects/{$case->id}/stage", ['stage_name' => 'Client review'])->assertOk();
        $this->assertSame('2026-10-01', $first->fresh()->actual_end_at->toDateString());
        // Stages jumped over are done but undated (unknown), the stage left gets today.
        $this->assertSame('Completed', $this->stage($case, 'Drawings prepared')->status);
        $review = $this->stage($case, 'Client review');
        $this->assertSame('In Progress', $review->status);
        $this->assertSame(Carbon::today()->toDateString(), $review->actual_start_at->toDateString());
        $this->assertSame('auto', $review->start_source);

        // Moving back clears auto dates on later stages, keeps the log.
        $this->postJson("/api/projects/{$case->id}/stage", ['stage_name' => 'Drawings prepared'])->assertOk();
        $this->assertNull($this->stage($case, 'Client review')->actual_start_at);
        $this->assertDatabaseHas('project_stage_events', ['project_id' => $case->id, 'stage_name' => 'Client review', 'event' => 'started', 'source' => 'stage_picker']);
        $this->assertSame('2026-10-01', $first->fresh()->actual_end_at->toDateString());
    }

    public function test_start_comes_from_the_previous_stage_and_the_log_lists_entries(): void
    {
        $case = $this->createCase();
        $stages = $case->stages()->orderBy('sequence_order')->get();
        $this->postJson("/api/projects/{$case->id}/stage-events", ['stage_id' => $stages[0]->id, 'event' => 'completed', 'event_date' => '2026-09-10'])->assertCreated();

        $workspace = collect($this->getJson("/api/projects/{$case->id}/workspace")->assertOk()->json('stages'))->keyBy('id');
        $second = $workspace[$stages[1]->id];
        $this->assertSame('In Progress', $second['status']);
        $this->assertTrue($second['start_from_previous']);
        $this->assertStringStartsWith('2026-09-10', $second['effective_start_at']);

        $log = $this->getJson("/api/projects/{$case->id}/stage-events")->assertOk()->json('events');
        $this->assertSame('Recorded', $log[0]['source_label']);
        $this->postJson("/api/projects/{$case->id}/stage-events", ['stage_id' => $stages[0]->id, 'event' => 'completed', 'event_date' => now()->addDays(5)->toDateString()])->assertStatus(422);

        // Removing the entry clears the date it set.
        $this->deleteJson("/api/stage-events/{$log[0]['id']}")->assertOk();
        $this->assertNull($stages[0]->fresh()->actual_end_at);
    }

    public function test_backlog_and_suggestions_for_a_finished_case(): void
    {
        $case = $this->createCase('NPV', 'US', '002', ['idf_received_date' => '2026-01-05']);
        $this->postJson("/api/projects/{$case->id}/docket/events", ['event_type' => 'us_filing_receipt', 'event_date' => '2026-03-12'])->assertCreated();
        $case->update(['status' => 'Granted']);
        $stages = $case->stages()->orderBy('sequence_order')->get();

        $suggestions = collect($this->getJson("/api/projects/{$case->id}/stage-events")->json('suggestions'));
        $this->assertTrue($suggestions->contains(fn ($s) => $s['stage_id'] === $stages[0]->id && $s['event'] === 'started' && $s['date'] === '2026-01-05'));

        $rows = $stages->take(3)->values()->map(fn ($stage, $i) => ['stage_id' => $stage->id, 'completed_on' => '2026-02-0'.($i + 1)])->all();
        $this->putJson("/api/projects/{$case->id}/stage-events/bulk", ['rows' => $rows])->assertOk()->assertJsonPath('dates', 3);
        $this->assertSame('2026-02-03', $stages[2]->fresh()->actual_end_at->toDateString());
        $this->assertSame('manual', $stages[2]->fresh()->end_source);
        $this->assertDatabaseHas('audit_logs', ['action' => 'backlog_stage_dates', 'subject_id' => $case->id]);
        $this->assertSame(3, DB::table('project_stage_events')->where('project_id', $case->id)->where('source', 'bulk')->count());
    }

    public function test_marking_a_case_granted_does_not_invent_dates(): void
    {
        $case = $this->createCase('NPV', 'US', '003');
        $this->putJson("/api/projects/{$case->id}", ['status' => 'Granted', 'status_event_date' => '2026-05-01'])->assertOk();
        $stages = $case->stages()->orderBy('sequence_order')->get();
        $this->assertSame(0, $stages->where('status', '!=', 'Completed')->count());
        // Only the stage that was in progress gets today; the rest stay undated for back-logging.
        $this->assertNotNull($stages[0]->actual_end_at);
        $this->assertNull($stages->last()->actual_end_at);
    }
}
