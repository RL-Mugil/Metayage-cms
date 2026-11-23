<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inventor;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\User;
use App\Services\LifecycleReapplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Existing cases move onto their office's template; the list picker uses the case's stages; FFP step. */
class UsLifecycleReapplyTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::create(['name' => 'Partner', 'email' => 'partner@ffp.test', 'password' => bcrypt('password'), 'role' => 'partner', 'status' => 'Active']));
        $this->client = Client::create([
            'client_code' => '401Y', 'client_type' => 'organization', 'legal_name' => 'Aivar', 'company_name' => 'Aivar',
            'nationality' => 'United States', 'status' => 'Active',
        ]);
    }

    private function inventor(string $name, string $nationality): Inventor
    {
        return Inventor::create([
            'client_id' => $this->client->id, 'full_name' => $name, 'nationality' => $nationality,
            'address_street' => 'Main Road', 'city' => 'Coimbatore', 'state' => 'Tamil Nadu', 'country' => 'India', 'pincode' => '641001',
        ]);
    }

    private function createCase(string $office, string $service, string $seq = '001', array $inventorIds = []): Project
    {
        $docket = "401Y{$seq}{$office}{$service}";
        $id = $this->postJson('/api/projects', [
            'record_mode' => 'existing', 'project_code' => $docket, 'docket_number' => $docket,
            'client_id' => $this->client->id, 'project_name' => "Case {$docket}", 'project_type' => 'Patent',
            'patent_office_code' => $office, 'service_code' => $service, 'inventor_ids' => $inventorIds,
        ])->assertCreated()->json('id');

        return Project::findOrFail($id);
    }

    private function names(Project $project): array
    {
        return $project->stages()->orderBy('sequence_order')->pluck('stage_name')->all();
    }

    /** Puts the case back on the generic US list, as cases created before the US templates were. */
    private function onGenericUsList(Project $project, int $completed, int $total = 7): void
    {
        $generic = DB::table('jurisdiction_lifecycle_templates')->where('jurisdiction', 'US')->where('service_code', '*')->first();
        $project->stages()->delete();
        for ($i = 0; $i < $total; $i++) {
            ProjectStage::create(['project_id' => $project->id, 'stage_name' => "Generic stage {$i}", 'sequence_order' => $i,
                'status' => $i < $completed ? 'Completed' : ($i === $completed ? 'In Progress' : 'Pending')]);
        }
        $project->update(['jurisdiction_lifecycle_template_id' => $generic->id, 'lifecycle_template_version' => $generic->version]);
    }

    public function test_an_existing_us_case_moves_onto_its_own_template(): void
    {
        $case = $this->createCase('US', 'PRD');
        $this->onGenericUsList($case, 7);   // all seven generic stages marked done

        $plan = app(LifecycleReapplyService::class)->reapply($case->fresh(), 'test-1');
        $this->assertSame(6, $plan['new_completed']);   // never all done while the case is open
        $case = $case->fresh();
        $this->assertSame('PRD', $case->lifecycleTemplate->service_code);
        $this->assertSame('Instructions confirmed', $this->names($case)[0]);
        $this->assertSame('In Progress', $case->stages()->where('sequence_order', 6)->value('status'));
        $this->assertDatabaseHas('project_stage_migration_snapshots', ['project_id' => $case->id, 'migration_version' => 'test-1']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'lifecycle_template_reapplied', 'subject_id' => $case->id]);

        // Already on its template: left alone.
        $this->assertNull(app(LifecycleReapplyService::class)->reapply($case, 'test-2'));

        // A granted case gets every stage completed.
        $granted = $this->createCase('US', 'NOA', '002');
        $this->onGenericUsList($granted, 2);
        $granted->update(['status' => 'Granted']);
        app(LifecycleReapplyService::class)->reapply($granted->fresh(), 'test-1');
        $this->assertSame(0, $granted->stages()->where('status', '!=', 'Completed')->count());
    }

    public function test_list_returns_each_cases_stages_and_picking_one_keeps_them(): void
    {
        $case = $this->createCase('US', 'NFO');
        $row = collect($this->getJson('/api/projects?per_page=50')->assertOk()->json('data'))->firstWhere('id', $case->id);
        $this->assertSame($this->names($case), $row['stage_names']);
        $this->assertSame('Office action docketed', $row['stage_names'][0]);

        $this->postJson("/api/projects/{$case->id}/stage", ['stage_name' => 'Attorney review'])->assertOk();
        $this->assertSame($row['stage_names'], $this->names($case->fresh()));   // not replaced by India's list
        $this->assertSame('In Progress', $case->stages()->where('stage_name', 'Attorney review')->value('status'));
    }

    public function test_foreign_filing_permission_step_follows_inventor_nationality(): void
    {
        $indian = $this->inventor('RAJENDRAN Koushik', 'Indian');
        $case = $this->createCase('US', 'PRD', '001', [$indian->id]);
        $names = $this->names($case);
        $ffp = array_search('Foreign filing permission – India (Form 25, Sec. 39)', $names, true);
        $this->assertNotFalse($ffp);
        $this->assertSame('Ready to file / handed to filing', $names[$ffp + 1]);   // just before filing
        $this->assertSame(range(0, count($names) - 1), $case->stages()->orderBy('sequence_order')->pluck('sequence_order')->all());

        // Removing the inventor removes the step.
        $this->putJson("/api/projects/{$case->id}", ['inventor_ids' => []])->assertOk();
        $this->assertNotContains('Foreign filing permission – India (Form 25, Sec. 39)', $this->names($case->fresh()));

        // Indian case with Indian inventors: none. US office action: none.
        $this->assertEmpty(array_filter($this->names($this->createCase('IN', 'PRV', '002', [$indian->id])), fn ($n) => str_starts_with($n, 'Foreign filing')));
        $this->assertEmpty(array_filter($this->names($this->createCase('US', 'NFO', '003', [$indian->id])), fn ($n) => str_starts_with($n, 'Foreign filing')));

        // Indian case with an American inventor: the US licence step.
        $american = $this->inventor('SMITH John', 'American');
        $this->assertContains('Foreign filing licence – US (35 USC 184)', $this->names($this->createCase('IN', 'PRV', '004', [$american->id])));

        // Changing an inventor's nationality updates their cases.
        $us = $this->createCase('US', 'PRV', '005', [$american->id]);
        $this->assertEmpty(array_filter($this->names($us), fn ($n) => str_starts_with($n, 'Foreign filing')));
        $this->putJson("/api/inventors/{$american->id}", ['full_name' => 'SMITH John', 'nationality' => 'Indian'])->assertOk();
        $this->assertContains('Foreign filing permission – India (Form 25, Sec. 39)', $this->names($us->fresh()));
    }
}
