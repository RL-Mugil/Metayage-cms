<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ReportExport;
use App\Models\TrackerRow;
use App\Models\TrackerCircle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_can_generate_every_report_type(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $types = [
            'client-portfolio', 'matter-status', 'financial-summary', 'hrms',
            'ip-deadline', 'productivity', 'tracker-workload', 'overdue-cases',
            'deadline-forecast', 'payment-collection', 'zoho-summary',
        ];

        foreach ($types as $type) {
            $this->postJson('/api/reports/generate', ['type' => $type, 'format' => 'CSV'])
                ->assertOk()
                ->assertJsonStructure(['type', 'rows', 'total', 'generated_at', 'export_id']);
        }

        $this->assertSame(count($types), ReportExport::query()->count());
    }

    public function test_generated_report_contains_every_matching_row_not_only_first_page(): void
    {
        $partner = $this->user('partner');
        $client = $this->client($partner);
        foreach (range(1, 105) as $sequence) {
            $this->project($client, $partner, $sequence, 'Active');
        }
        $this->project($client, $partner, 999, 'Closed');

        Sanctum::actingAs($partner);
        $response = $this->postJson('/api/reports/generate', [
            'type' => 'matter-status', 'format' => 'CSV',
        ])->assertOk()->assertJsonPath('total', 105);

        $this->assertCount(105, $response->json('rows'));
        $export = ReportExport::query()->sole();
        $this->assertSame(105, $export->row_count);
        $this->assertCount(105, $export->snapshot);
    }

    public function test_deadline_forecast_defaults_to_next_sixty_days(): void
    {
        $partner = $this->user('partner');
        $client = $this->client($partner);
        $inside = $this->project($client, $partner, 1, 'Active');
        $outside = $this->project($client, $partner, 2, 'Active');
        $circle = TrackerCircle::query()->create(['name' => 'Reports', 'slug' => 'reports']);
        TrackerRow::query()->create([
            'circle_id' => $circle->id,
            'project_id' => $inside->id, 'docket_number' => $inside->docket_number,
            'client_name' => $client->company_name, 'delivery_due_date' => now()->addDays(30)->toDateString(),
        ]);
        TrackerRow::query()->create([
            'circle_id' => $circle->id,
            'project_id' => $outside->id, 'docket_number' => $outside->docket_number,
            'client_name' => $client->company_name, 'delivery_due_date' => now()->addDays(90)->toDateString(),
        ]);

        Sanctum::actingAs($partner);
        $this->getJson('/api/reports/data?type=deadline-forecast')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonFragment(['Docket #' => $inside->docket_number]);
    }

    public function test_invalid_report_type_and_reversed_dates_are_rejected(): void
    {
        Sanctum::actingAs($this->user('partner'));
        $this->getJson('/api/reports/data?type=unknown')->assertUnprocessable();
        $this->getJson('/api/reports/data?type=matter-status&from_date=2026-08-20&to_date=2026-08-01')
            ->assertUnprocessable();
    }

    public function test_manager_cannot_see_another_managers_productivity(): void
    {
        $manager = $this->user('manager', 'manager@example.test');
        $other = $this->user('manager', 'other-manager@example.test');
        $client = $this->client($other);
        $project = $this->project($client, $other, 1, 'Active');
        \App\Models\TimeEntry::query()->create([
            'user_id' => $other->id, 'project_id' => $project->id,
            'duration_hours' => 5, 'entry_date' => now()->toDateString(),
            'billable' => true, 'status' => 'Approved',
        ]);

        Sanctum::actingAs($manager);
        $this->getJson('/api/reports/data?type=productivity')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_client_code_filters_client_reports_and_blank_code_keeps_full_report(): void
    {
        $partner = $this->user('partner');
        $firstClient = $this->client($partner, 'RPT1', 'First Reports Client');
        $secondClient = $this->client($partner, 'RPT2', 'Second Reports Client');
        $this->project($firstClient, $partner, 1, 'Active');
        $this->project($secondClient, $partner, 2, 'Active');

        Sanctum::actingAs($partner);

        $this->postJson('/api/reports/generate', [
            'type' => 'matter-status',
            'format' => 'CSV',
            'client_code' => 'rpt2',
        ])->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('rows.0.client', 'Second Reports Client');

        $this->assertSame('RPT2', ReportExport::query()->sole()->filters['client_code']);

        $this->getJson('/api/reports/data?type=matter-status&client_code=')
            ->assertOk()
            ->assertJsonPath('total', 2);

        $this->getJson('/api/reports/data?type=matter-status&client_code=missing')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_code');
    }

    public function test_case_reports_include_protected_identifiers_and_notes_as_last_column(): void
    {
        $partner = $this->user('partner');
        $client = $this->client($partner);
        $project = $this->project($client, $partner, 1, 'Active');
        $project->update(['application_number' => '202641000001']);

        Sanctum::actingAs($partner);
        $response = $this->getJson('/api/reports/data?type=matter-status')
            ->assertOk()
            ->assertJsonPath('rows.0.client_code', 'RPT1')
            ->assertJsonPath('rows.0.application_number', '202641000001')
            ->assertJsonPath('rows.0.Notes', '');

        $this->assertSame('Notes', array_key_last($response->json('rows.0')));
    }

    private function user(string $role, ?string $email = null): User
    {
        return User::query()->create([
            'name' => str($role)->headline()->toString(),
            'email' => $email ?? "{$role}@reports.example.test",
            'password' => 'password', 'role' => $role, 'status' => 'Active',
        ]);
    }

    private function client(
        User $manager,
        string $code = 'RPT1',
        string $companyName = 'Reports Client',
    ): Client
    {
        return Client::query()->create([
            'client_code' => $code, 'company_name' => $companyName,
            'account_manager_id' => $manager->id, 'status' => 'Active',
        ]);
    }

    private function project(Client $client, User $manager, int $sequence, string $status): Project
    {
        return Project::query()->create([
            'project_code' => sprintf('PRJ-2026-%05d', $sequence),
            'docket_number' => sprintf('RPT1%03dINPAT', $sequence),
            'client_id' => $client->id, 'project_type' => 'Patent',
            'project_name' => "Report Matter {$sequence}",
            'assigned_manager_id' => $manager->id, 'status' => $status,
        ]);
    }
}
