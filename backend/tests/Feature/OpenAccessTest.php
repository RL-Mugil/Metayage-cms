<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Patent Analysts / Engineers and Patent Attorneys see only their own clients (Client Manager,
 * or PCM / SCM on a case) and cases, unless System Admin turns on Settings → System → Open access.
 */
class OpenAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->user('super_admin', 'sa@open.test');
    }

    private function user(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role).' '.$email, 'email' => $email, 'password' => bcrypt('x'), 'role' => $role, 'status' => 'Active']);
    }

    private function client(string $code, array $extra = []): Client
    {
        return Client::create(['client_code' => $code, 'client_type' => 'organization', 'legal_name' => "Client {$code}", 'company_name' => "Client {$code}",
            'nationality' => 'India', 'status' => 'Active', ...$extra]);
    }

    private function createCase(Client $client, string $docket): Project
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/projects', [
            'record_mode' => 'existing', 'project_code' => $docket, 'docket_number' => $docket, 'client_id' => $client->id,
            'project_name' => "Case {$docket}", 'project_type' => 'Patent', 'patent_office_code' => 'IN', 'service_code' => 'PRV',
        ])->assertCreated()->json('id');

        return Project::findOrFail($id);
    }

    private function setOpen(bool $clients, bool $cases, bool $portal): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/settings/open-access', compact('clients', 'cases', 'portal'))->assertOk();
    }

    private function clientIds(): array
    {
        return collect($this->getJson('/api/clients?per_page=100')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    public function test_clients_page_shows_own_clients_until_the_clients_switch_is_on(): void
    {
        $attorney = $this->user('manager', 'pa@open.test');
        $analyst = $this->user('associate', 'an@open.test');

        $a = $this->client('801M');                                         // attorney is PCM on a case
        $b = $this->client('802M', ['account_manager_id' => $attorney->id]); // attorney is Client Manager
        $c = $this->client('803M');                                         // nobody's yet
        $this->createCase($a, '801M001INPRV')->update(['assigned_manager_id' => $attorney->id]);

        Sanctum::actingAs($attorney);
        $this->assertSame([$a->id, $b->id], $this->clientIds());
        $this->getJson("/api/clients/{$c->id}")->assertForbidden();
        $this->getJson("/api/clients/{$c->id}/detail")->assertForbidden();
        $this->putJson("/api/clients/{$c->id}", ['account_manager_id' => $attorney->id])->assertForbidden();
        $this->assertSame(2, $this->getJson('/api/clients/stats')->assertOk()->json('total'));

        Sanctum::actingAs($analyst);
        $this->assertSame([], $this->clientIds());

        // System Admin opens Clients: the analyst sees all and claims C.
        $this->setOpen(true, false, false);
        Sanctum::actingAs($analyst);
        $this->assertSame([$a->id, $b->id, $c->id], $this->clientIds());
        $this->putJson("/api/clients/{$c->id}", ['account_manager_id' => $analyst->id])->assertOk();
        $this->assertSame($analyst->id, (int) $c->fresh()->account_manager_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'update', 'subject_type' => 'Client', 'subject_id' => $c->id]);

        // Switch off: the analyst keeps the client they claimed, nothing else.
        $this->setOpen(false, false, false);
        Sanctum::actingAs($analyst);
        $this->assertSame([$c->id], $this->clientIds());
        $this->getJson("/api/clients/{$c->id}")->assertOk();
    }

    public function test_cases_switch_opens_cases_but_not_bulk_or_finance(): void
    {
        $analyst = $this->user('associate', 'an2@open.test');
        $client = $this->client('804M');
        $case = $this->createCase($client, '804M001INPRV');

        Sanctum::actingAs($analyst);
        $this->getJson("/api/projects/{$case->id}")->assertForbidden();

        $this->setOpen(false, true, false);
        Sanctum::actingAs($analyst);
        $this->getJson("/api/projects/{$case->id}")->assertOk();
        $this->assertTrue($analyst->fresh()->can('update', $case->fresh()));

        // Bulk ignores the switch.
        $res = $this->postJson('/api/bulk/execute', ['entity' => 'cases', 'ids' => [$case->id], 'action' => 'circle', 'params' => ['circle' => 'B']])->assertOk()->json();
        $this->assertSame(0, $res['ok']);

        // Finance stays on CM / SCM cases only.
        $this->assertFalse(\App\Support\DataAccess::seesAllFinance($analyst));
        $this->assertTrue(\App\Support\DataAccess::canOpenFinance($analyst));
    }

    public function test_portal_switch_and_who_may_change_it(): void
    {
        $attorney = $this->user('manager', 'pa3@open.test');
        $portalUser = $this->user('client_admin', 'cl@open.test');
        $this->client('805M', ['portal_user_id' => $portalUser->id, 'portal_enabled' => true]);

        Sanctum::actingAs($attorney);
        $this->assertSame([], $this->getJson('/api/portal/clients')->assertOk()->json());
        $this->putJson('/api/settings/open-access', ['clients' => true, 'cases' => true, 'portal' => true])->assertForbidden();

        $this->setOpen(false, false, true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'update_open_access']);
        Sanctum::actingAs($attorney);
        $this->assertCount(1, $this->getJson('/api/portal/clients')->assertOk()->json());
        // The Clients page is a separate switch.
        $this->assertSame([], $this->clientIds());
    }
}
