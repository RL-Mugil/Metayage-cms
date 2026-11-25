<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The Client Portal page lists only the clients where a case-scoped user is PCM or SCM. */
class PortalScopeTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role).' '.$email, 'email' => $email, 'password' => bcrypt('x'), 'role' => $role, 'status' => 'Active']);
    }

    private function client(string $code, string $name): Client
    {
        $portalUser = $this->user('client_admin', strtolower($code).'@client.test');

        return Client::create(['client_code' => $code, 'client_type' => 'organization', 'legal_name' => $name, 'company_name' => $name,
            'nationality' => 'India', 'status' => 'Active', 'portal_user_id' => $portalUser->id, 'portal_enabled' => true]);
    }

    private function createCase(Client $client, string $docket): Project
    {
        $id = $this->postJson('/api/projects', [
            'record_mode' => 'existing', 'project_code' => $docket, 'docket_number' => $docket, 'client_id' => $client->id,
            'project_name' => "Case {$docket}", 'project_type' => 'Patent', 'patent_office_code' => 'IN', 'service_code' => 'PRV',
        ])->assertCreated()->json('id');

        return Project::findOrFail($id);
    }

    public function test_case_scoped_staff_see_only_their_pcm_and_scm_clients(): void
    {
        $admin = $this->user('super_admin', 'sa@portal.test');
        $attorney = $this->user('manager', 'pa@portal.test');
        $analyst = $this->user('associate', 'an@portal.test');

        $a = $this->client('701M', 'Alpha Co');   // attorney is PCM
        $b = $this->client('702M', 'Beta Co');    // attorney is SCM
        $c = $this->client('703M', 'Gamma Co');   // attorney is only the Patent Engineer

        Sanctum::actingAs($admin);
        $this->createCase($a, '701M001INPRV')->update(['assigned_manager_id' => $attorney->id]);
        $this->createCase($b, '702M001INPRV')->update(['secondary_manager_id' => $attorney->id]);
        $this->createCase($c, '703M001INPRV')->update(['patent_engineer_id' => $attorney->id]);

        $this->assertCount(3, $this->getJson('/api/portal/clients')->assertOk()->json());

        Sanctum::actingAs($attorney);
        $ids = collect($this->getJson('/api/portal/clients')->assertOk()->json())->pluck('id')->sort()->values()->all();
        $this->assertSame([$a->id, $b->id], $ids);

        $mine = collect($this->getJson('/api/clients?mine=1&per_page=100')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$a->id, $b->id], $mine);

        // Actions on another client are refused.
        $this->getJson("/api/portal/clients/{$c->id}/users")->assertNotFound();
        $this->postJson("/api/portal/clients/{$c->id}/toggle")->assertNotFound();
        $this->postJson('/api/portal/bulk', ['action' => 'disable', 'ids' => [$a->id, $c->id]])->assertForbidden();
        $this->assertTrue((bool) $c->fresh()->portal_enabled);

        $this->postJson("/api/portal/clients/{$a->id}/toggle")->assertOk();
        $this->assertFalse((bool) $a->fresh()->portal_enabled);

        // A Patent Analyst with no PCM / SCM case sees no clients.
        Sanctum::actingAs($analyst);
        $this->assertSame([], $this->getJson('/api/portal/clients')->assertOk()->json());
    }
}
