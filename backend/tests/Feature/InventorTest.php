<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inventor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Firm-wide inventor records, picked on the case form and reused across cases. */
class InventorTest extends TestCase
{
    use RefreshDatabase;

    private User $paralegal;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->paralegal = $this->user('paralegal', 'para@firm.test');
        $this->client = Client::create([
            'client_code' => '397M', 'client_type' => 'organization', 'legal_name' => 'Acme', 'company_name' => 'Acme',
            'nationality' => 'India', 'status' => 'Active',
        ]);
    }

    private function user(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $email, 'password' => bcrypt('password'), 'role' => $role, 'status' => 'Active']);
    }

    private function project(string $docket): Project
    {
        return Project::create([
            'project_code' => $docket, 'docket_number' => $docket, 'client_id' => $this->client->id,
            'project_type' => 'Patent', 'project_name' => 'Case '.$docket, 'patent_office_code' => 'IN',
            'status' => 'Open', 'patent_engineer_id' => $this->paralegal->id,
        ]);
    }

    private function particulars(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'SHARMA Priya', 'gender' => 'Female', 'age' => 34, 'nationality' => 'Indian',
            'country_of_residence' => 'India', 'address_house' => '7B', 'address_street' => 'Park Street',
            'city' => 'Kolkata', 'state' => 'West Bengal', 'country' => 'India', 'pincode' => '700016',
            'email' => 'priya@acme.test', 'phone' => '9000011111',
        ], $overrides);
    }

    public function test_paralegal_creates_searches_and_updates_an_inventor(): void
    {
        Sanctum::actingAs($this->paralegal);

        $created = $this->postJson('/api/inventors', $this->particulars())->assertCreated()->json();
        $this->assertTrue($created['complete']);
        $this->assertFalse($created['has_portal_access']);

        // Personal particulars are encrypted at rest.
        $raw = DB::table('inventors')->where('id', $created['id'])->first();
        $this->assertNotSame('Park Street', $raw->address_street);
        $this->assertNotSame('700016', $raw->pincode);

        $this->getJson('/api/inventors?search=shar')->assertOk()->assertJsonPath('0.id', $created['id']);
        $this->getJson('/api/inventors?search=nobody')->assertOk()->assertJsonCount(0);

        $this->putJson("/api/inventors/{$created['id']}", ['full_name' => 'SHARMA Priya', 'city' => 'Howrah'])
            ->assertOk()->assertJsonPath('city', 'Howrah')->assertJsonPath('pincode', '700016');
        $this->assertDatabaseHas('audit_logs', ['action' => 'update_inventor', 'subject_id' => $created['id']]);
    }

    public function test_incomplete_inventor_is_flagged(): void
    {
        Sanctum::actingAs($this->paralegal);

        $this->postJson('/api/inventors', ['full_name' => 'Only A Name'])->assertCreated()->assertJsonPath('complete', false);
        $this->postJson('/api/inventors', ['city' => 'Pune'])->assertStatus(422);   // name is required
    }

    public function test_case_form_sets_inventors_in_order_and_they_are_reused_across_cases(): void
    {
        $first = Inventor::create($this->particulars());
        $second = Inventor::create($this->particulars(['full_name' => 'RAO Kiran']));
        $caseA = $this->project('397M001INPAT');
        $caseB = $this->project('397M002INPAT');
        Sanctum::actingAs($this->paralegal);

        $this->putJson("/api/projects/{$caseA->id}", ['inventor_ids' => [$second->id, $first->id]])->assertOk();
        $this->assertSame([$second->id, $first->id], $caseA->inventors()->pluck('inventors.id')->all());

        $this->putJson("/api/projects/{$caseB->id}/inventors", ['inventor_ids' => [$first->id]])
            ->assertOk()->assertJsonPath('0.full_name', 'SHARMA Priya');
        $this->assertSame(2, $first->projects()->count());

        // Updates that don't mention inventors leave them alone.
        $this->putJson("/api/projects/{$caseA->id}", ['urgency' => 'High'])->assertOk();
        $this->assertSame(2, $caseA->inventors()->count());

        $this->putJson("/api/projects/{$caseA->id}/inventors", ['inventor_ids' => []])->assertOk();
        $this->assertSame(0, $caseA->inventors()->count());
        $this->getJson("/api/projects/{$caseB->id}/inventors")->assertOk()->assertJsonCount(1);
    }

    public function test_new_case_can_be_created_with_inventors(): void
    {
        $inventor = Inventor::create($this->particulars());
        $partner = $this->user('partner', 'partner@firm.test');
        Sanctum::actingAs($partner);

        $id = $this->postJson('/api/projects', [
            'record_mode' => 'existing', 'project_code' => '397M009INPRV', 'docket_number' => '397M009INPRV',
            'client_id' => $this->client->id, 'project_name' => 'Smart Valve', 'project_type' => 'Patent',
            'patent_office_code' => 'IN', 'service_code' => 'PRV', 'inventor_ids' => [$inventor->id],
        ])->assertCreated()->json('id');

        $this->assertSame([$inventor->id], Project::findOrFail($id)->inventors()->pluck('inventors.id')->all());
    }

    public function test_unauthorised_roles_cannot_manage_inventors(): void
    {
        $inventor = Inventor::create($this->particulars());
        $case = $this->project('397M001INPAT');

        foreach (['finance' => 'fin@firm.test', 'hr' => 'hr@firm.test', 'client' => 'portal@acme.test', 'inventor' => 'inv@acme.test'] as $role => $email) {
            Sanctum::actingAs($this->user($role, $email));
            $this->getJson('/api/inventors')->assertForbidden();
            $this->postJson('/api/inventors', $this->particulars())->assertForbidden();
            $this->putJson("/api/inventors/{$inventor->id}", ['full_name' => 'X'])->assertForbidden();
            $this->putJson("/api/projects/{$case->id}/inventors", ['inventor_ids' => [$inventor->id]])->assertForbidden();
        }

        // A paralegal not assigned to the case cannot change its inventors.
        Sanctum::actingAs($this->user('paralegal', 'other-para@firm.test'));
        $this->putJson("/api/projects/{$case->id}/inventors", ['inventor_ids' => [$inventor->id]])->assertForbidden();
    }
}
