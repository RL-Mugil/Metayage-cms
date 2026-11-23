<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** New/Edit Client form: country + state, Foreign Company → Export, staff-only manager, referrer code. */
class ClientFormRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->partner = $this->user('partner', 'partner@firm.test');
        Sanctum::actingAs($this->partner);
    }

    private function user(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $email, 'password' => bcrypt('password'), 'role' => $role, 'status' => 'Active']);
    }

    private function data(array $override = []): array
    {
        return array_merge(['legal_name' => 'Aivar Inc', 'client_type' => 'organization', 'country' => 'India', 'status' => 'Active'], $override);
    }

    public function test_foreign_company_is_export_and_drops_indian_registrations(): void
    {
        $response = $this->postJson('/api/clients', $this->data([
            'entity_subtype' => 'Foreign Company', 'country' => 'United States', 'state' => 'California',
            'pan_number' => 'ABCDE1234F', 'cin_number' => 'U12345MH2020PTC123456', 'has_gstin' => true, 'gstin' => '27AAPFU0939F1ZV',
        ]))->assertCreated()->json();

        $this->assertSame('Export', $response['gst_type']);
        $client = Client::findOrFail($response['id']);
        $this->assertSame('United States', $client->country);
        $this->assertSame('United States', $client->nationality);
        $this->assertSame('California', $client->state);
        $this->assertNull($client->pan_number);
        $this->assertNull($client->cin_number);
        $this->assertNull($client->gstin);
        $this->assertFalse((bool) $client->has_gstin);
        $this->assertStringEndsWith('Y', $client->client_code);
    }

    public function test_foreign_company_cannot_be_in_india(): void
    {
        $this->postJson('/api/clients', $this->data(['entity_subtype' => 'Foreign Company', 'country' => 'India']))
            ->assertStatus(422)->assertJsonValidationErrors('country');
    }

    public function test_indian_company_keeps_its_registrations(): void
    {
        $response = $this->postJson('/api/clients', $this->data([
            'entity_subtype' => 'Private Limited (Pvt Ltd)', 'state' => 'Karnataka', 'pan_number' => 'ABCDE1234F',
            'has_gstin' => true, 'gstin' => '27AAPFU0939F1ZV',
        ]))->assertCreated()->json();

        $this->assertSame('B2B', $response['gst_type']);
        $this->assertSame('ABCDE1234F', Client::findOrFail($response['id'])->pan_number);
    }

    public function test_client_manager_must_be_firm_staff(): void
    {
        $portal = $this->user('client_admin', 'portal@client.test');
        $inventor = $this->user('inventor', 'inventor@client.test');

        $this->postJson('/api/clients', $this->data(['account_manager_id' => $portal->id]))->assertStatus(422)->assertJsonValidationErrors('account_manager_id');
        $this->postJson('/api/clients', $this->data(['account_manager_id' => $inventor->id]))->assertStatus(422);
        $this->postJson('/api/clients', $this->data(['account_manager_id' => $this->partner->id]))->assertCreated();

        // The staff-only list the Client Manager picker uses.
        $ids = collect($this->getJson('/api/users?staff=1')->assertOk()->json())->pluck('id');
        $this->assertTrue($ids->contains($this->partner->id));
        $this->assertFalse($ids->contains($portal->id));
        $this->assertFalse($ids->contains($inventor->id));
    }

    public function test_referrer_must_be_an_existing_client_code_of_any_length(): void
    {
        Client::create(['client_code' => 'LEGACY-A97M', 'legal_name' => 'Old', 'company_name' => 'Old', 'client_type' => 'organization', 'nationality' => 'India', 'status' => 'Active']);

        $this->postJson('/api/clients', $this->data(['referred_by_code' => 'NOPE1']))->assertStatus(422)->assertJsonValidationErrors('referred_by_code');
        $id = $this->postJson('/api/clients', $this->data(['referred_by_code' => 'LEGACY-A97M']))->assertCreated()->json('id');
        $this->assertSame('LEGACY-A97M', Client::findOrFail($id)->referred_by_code);

        // An unchanged legacy value already on the client still saves.
        Client::whereKey($id)->update(['referred_by_code' => 'GONE1']);
        $this->putJson("/api/clients/{$id}", ['referred_by_code' => 'GONE1', 'legal_name' => 'Aivar Inc'])->assertOk();
        $this->putJson("/api/clients/{$id}", ['referred_by_code' => 'GONE2'])->assertStatus(422);
    }
}
