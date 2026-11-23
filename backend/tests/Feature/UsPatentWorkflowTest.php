<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DocketDeadline;
use App\Models\Project;
use App\Models\RenewalSchedule;
use App\Models\User;
use App\Services\JurisdictionLifecycleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** US (USPTO) cases: stage lists per firm code, USPTO deadlines, maintenance fees, lifecycle counts. */
class UsPatentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::create(['name' => 'Partner', 'email' => 'partner@us.test', 'password' => bcrypt('password'), 'role' => 'partner', 'status' => 'Active']));
        $this->client = Client::create([
            'client_code' => '399M', 'client_type' => 'organization', 'legal_name' => 'Client 399',
            'company_name' => 'Client 399', 'nationality' => 'India', 'status' => 'Active',
        ]);
    }

    private function createCase(string $office, string $service, string $seq = '001'): Project
    {
        $docket = "399M{$seq}{$office}{$service}";
        $id = $this->postJson('/api/projects', [
            'record_mode' => 'existing', 'project_code' => $docket, 'docket_number' => $docket,
            'client_id' => $this->client->id, 'project_name' => "Case {$docket}", 'project_type' => 'Patent',
            'patent_office_code' => $office, 'service_code' => $service,
        ])->assertCreated()->json('id');

        return Project::with('stages')->findOrFail($id);
    }

    private function deadlines(Project $project): \Illuminate\Support\Collection
    {
        return DocketDeadline::where('project_id', $project->id)->get()->keyBy('title');
    }

    public function test_firm_us_codes_have_their_own_us_stage_lists(): void
    {
        $service = app(JurisdictionLifecycleService::class);
        foreach (['PRD', 'CPT', 'PIA', 'NPE', 'CON', 'FMP', 'IDS', 'TRC', 'RRT', 'OAR', 'NFO', 'FOA', 'QOA', 'EXI', 'APL', 'NOA', 'MFE', 'REN', 'PGV'] as $code) {
            $this->assertSame($code, $service->resolve('US', $code)->service_code, "US {$code} should have its own template, not the generic one.");
        }

        $nfo = $this->createCase('US', 'NFO');
        $this->assertSame('Office action docketed', $nfo->stages->sortBy('sequence_order')->first()->stage_name);
        $noa = $this->createCase('US', 'NOA', '002');
        $this->assertSame('Notice of allowance docketed', $noa->stages->sortBy('sequence_order')->first()->stage_name);
        // India keeps its own lists.
        $fer = $this->createCase('IN', 'FER', '003');
        $this->assertSame('FER received and docketed', $fer->stages->sortBy('sequence_order')->first()->stage_name);
    }

    public function test_uspto_events_create_us_deadlines_and_never_india_ones(): void
    {
        $case = $this->createCase('US', 'NFO');
        $url = "/api/projects/{$case->id}/docket/events";

        $this->postJson($url, ['event_type' => 'us_nonfinal_office_action', 'event_date' => '2026-01-15'])->assertCreated();
        $oa = $this->deadlines($case)['Response to non-final office action due'];
        $this->assertSame('2026-04-15', Carbon::parse($oa->due_date)->toDateString());
        $this->assertSame('2026-07-15', Carbon::parse($oa->extended_due_date)->toDateString());
        $this->assertSame('US-PAT-2026.1', $oa->rule_code);
        // The NFO stepper's first stage completed on the event.
        $this->assertSame('Completed', $case->stages()->where('sequence_order', 0)->value('status'));

        $this->postJson($url, ['event_type' => 'us_notice_of_allowance', 'event_date' => '2026-03-01'])->assertCreated();
        $fee = $this->deadlines($case)['Issue fee due'];
        $this->assertSame('2026-06-01', Carbon::parse($fee->due_date)->toDateString());
        $this->assertNull($fee->extended_due_date);   // not extendable

        $this->postJson($url, ['event_type' => 'provisional_filed', 'event_date' => '2026-02-10'])->assertCreated();
        $conversion = $this->deadlines($case)['Non-provisional / PCT application due (claims the provisional)'];
        $this->assertSame('2027-02-10', Carbon::parse($conversion->due_date)->toDateString());
        $this->assertFalse($this->deadlines($case)->has('Complete Specification Due'));

        $before = DocketDeadline::where('project_id', $case->id)->count();
        $this->postJson($url, ['event_type' => 'fer_received', 'event_date' => '2026-02-11'])->assertCreated();
        $this->assertSame($before, DocketDeadline::where('project_id', $case->id)->count());   // India rule: nothing on a US case

        // The docket offers USPTO events on a US case.
        $types = $this->getJson("/api/projects/{$case->id}/docket")->assertOk()->json('event_types');
        $this->assertArrayHasKey('us_final_office_action', $types);
        $this->assertArrayNotHasKey('fer_received', $types);
    }

    public function test_us_grant_schedules_maintenance_fees_not_india_annuities(): void
    {
        $case = $this->createCase('US', 'NOA');
        $this->assertNotNull($case->patent_application_id);
        $this->postJson("/api/projects/{$case->id}/docket/events", ['event_type' => 'us_patent_issued', 'event_date' => '2026-05-12'])->assertCreated();

        $rows = RenewalSchedule::where('patent_application_id', $case->patent_application_id)->orderBy('renewal_year')->get();
        $this->assertSame([4, 8, 12], $rows->pluck('renewal_year')->all());
        $this->assertSame('Maintenance fee (3.5 years)', $rows[0]->label);
        $this->assertSame('2029-11-12', $rows[0]->due_date->toDateString());
        $this->assertSame('2029-05-12', $rows[0]->window_opens_on->toDateString());
        $this->assertSame('2030-05-12', $rows[0]->grace_ends_on->toDateString());
        $this->assertSame('2037-11-12', $rows[2]->due_date->toDateString());
        $this->assertSame('Granted', DB::table('patent_applications')->where('id', $case->patent_application_id)->value('legal_status'));

        // Recording the 7.5-year fee paid marks it and the 3.5-year fee Paid.
        $this->postJson("/api/projects/{$case->id}/docket/renewal-paid", ['paid_through_year' => 8, 'paid_date' => '2033-10-01'])->assertOk();
        $this->assertSame(['Paid', 'Paid', 'Unpaid'], $rows->fresh()->pluck('status')->all());
        $this->postJson("/api/projects/{$case->id}/docket/renewal-paid", ['paid_through_year' => 5, 'paid_date' => '2033-10-01'])->assertStatus(422);
    }

    public function test_india_grant_still_schedules_annual_renewals(): void
    {
        $case = $this->createCase('IN', 'FER');
        DB::table('patent_applications')->where('id', $case->patent_application_id)->update(['filing_date' => '2022-01-10']);
        $this->postJson("/api/projects/{$case->id}/docket/events", ['event_type' => 'granted', 'event_date' => '2026-05-12'])->assertCreated();

        $years = RenewalSchedule::where('patent_application_id', $case->patent_application_id)->orderBy('renewal_year')->pluck('renewal_year')->all();
        $this->assertSame(range(3, 20), $years);
    }

    public function test_lifecycle_counts_and_case_list_split_by_office(): void
    {
        $this->createCase('US', 'NPV');
        $this->createCase('IN', 'PRV', '002');
        $this->createCase('US', 'PRV', '003');

        $us = $this->getJson('/api/projects/lifecycle-service-stats?office=US')->assertOk()->json();
        $this->assertSame(1, (int) ($us['PRV'] ?? 0));
        $this->assertSame(1, (int) ($us['NPV'] ?? 0));
        $in = $this->getJson('/api/projects/lifecycle-service-stats?office=IN')->assertOk()->json();
        $this->assertSame(1, (int) ($in['PRV'] ?? 0));
        $this->assertArrayNotHasKey('NPV', $in);

        $list = $this->getJson('/api/projects?service_code=PRV&patent_office_code=US&per_page=50')->assertOk()->json();
        $rows = $list['data'] ?? $list;
        $this->assertCount(1, $rows);
        $this->assertSame('US', $rows[0]['patent_office_code']);
    }

    public function test_service_codes_are_tagged_by_country_in_settings(): void
    {
        $items = collect(json_decode(DB::table('system_settings')->where('key', 'dropdown_service_codes')->value('value'), true))->keyBy('code');
        $this->assertSame(['US'], $items['NFO']['countries']);
        $this->assertSame(['IN'], $items['FER']['countries']);
        $this->assertSame(['IN', 'US'], $items['PRV']['countries']);
        $this->assertSame([], $items['PAS']['countries']);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@us.test', 'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'Active']);
        Sanctum::actingAs($admin);
        $payload = $items->values()->map(fn ($item) => $item['code'] === 'IDS' ? array_merge($item, ['countries' => ['us', 'EP']]) : $item)->all();
        $this->putJson('/api/settings/dropdown', ['key' => 'dropdown_service_codes', 'items' => $payload])->assertOk();
        $saved = collect(json_decode(DB::table('system_settings')->where('key', 'dropdown_service_codes')->value('value'), true))->keyBy('code');
        $this->assertSame(['US', 'EP'], $saved['IDS']['countries']);

        $bad = $items->values()->map(fn ($item) => $item['code'] === 'IDS' ? array_merge($item, ['countries' => ['USA']]) : $item)->all();
        $this->putJson('/api/settings/dropdown', ['key' => 'dropdown_service_codes', 'items' => $bad])->assertStatus(422);
    }
}
