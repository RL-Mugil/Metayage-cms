<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inventor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Clients / Cases pages: inventor scope, and KPI numbers that match the lists they open. */
class ClientProjectScopeTest extends TestCase
{
    use RefreshDatabase;

    private function client(string $code): Client
    {
        return Client::create(['client_code' => $code, 'client_type' => 'organization', 'legal_name' => "Client {$code}", 'company_name' => "Client {$code}", 'nationality' => 'India', 'status' => 'Active']);
    }

    private function project(Client $client, string $docket, array $extra = []): Project
    {
        return Project::create(array_merge(['project_code' => $docket, 'docket_number' => $docket, 'client_id' => $client->id,
            'project_type' => 'Patent', 'project_name' => $docket, 'status' => 'Open'], $extra));
    }

    public function test_an_inventor_cannot_list_clients_and_project_stats_cover_only_their_cases(): void
    {
        $a = $this->client('811M');
        $b = $this->client('812M');
        $own = $this->project($a, '811M001INPRV');
        $this->project($b, '812M001INPRV');
        $this->project($b, '812M002INPRV');
        $login = User::create(['name' => 'Prof. Inventor', 'email' => 'inv@scope.test', 'password' => bcrypt('password'), 'role' => 'inventor', 'status' => 'Active']);
        $own->syncInventors([Inventor::create(['full_name' => 'Prof. Inventor', 'user_id' => $login->id])->id]);

        Sanctum::actingAs($login);
        $this->getJson('/api/clients')->assertForbidden();
        $this->getJson('/api/clients/stats')->assertForbidden();
        $this->getJson("/api/clients/{$b->id}")->assertForbidden();
        $this->assertSame(1, $this->getJson('/api/projects/stats')->assertOk()->json('total'));
    }

    public function test_overdue_skips_finished_cases_and_granted_kpi_matches_its_list(): void
    {
        Sanctum::actingAs(User::create(['name' => 'Partner', 'email' => 'p@scope.test', 'password' => bcrypt('password'), 'role' => 'partner', 'status' => 'Active']));
        $c = $this->client('813M');
        $past = now()->subDays(10)->toDateString();
        $this->project($c, '813M001INCPT', ['hard_deadline' => $past, 'status' => 'In Progress']);           // really overdue
        $this->project($c, '813M002INCPT', ['hard_deadline' => $past, 'status' => 'Granted']);               // finished
        $this->project($c, '813M003INCPT', ['hard_deadline' => $past, 'status' => 'Abandoned']);             // finished
        $this->project($c, '813M004INCPT', ['status' => 'Open', 'patent_granted' => true]);                  // granted flag only

        $stats = $this->getJson('/api/projects/stats')->assertOk()->json();
        $this->assertSame(1, $stats['overdue']);
        $this->assertSame(2, $stats['granted']);

        $overdue = collect($this->getJson('/api/projects?overdue=1&per_page=50')->assertOk()->json('data'))->pluck('docket_number');
        $this->assertEquals(['813M001INCPT'], $overdue->values()->all());
        $granted = collect($this->getJson('/api/projects?patent_granted=1&per_page=50')->assertOk()->json('data'))->pluck('docket_number')->sort()->values();
        $this->assertEquals(['813M002INCPT', '813M004INCPT'], $granted->all());
    }
}
