<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The client's address in the IPO-form parts: entered on the client, carried to the Forms tab. */
class ClientAddressSplitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::create(['name' => 'Partner', 'email' => 'p@addr.test', 'password' => bcrypt('x'), 'role' => 'partner', 'status' => 'Active']));
    }

    public function test_client_form_requires_the_indian_parts_and_composes_the_one_line_address(): void
    {
        $base = ['legal_name' => 'Luri Energy Private Limited', 'client_type' => 'organization', 'country' => 'India', 'status' => 'Active',
            'address_house' => 'No. 5', 'address_street' => 'Hosur Road', 'city' => 'Bengaluru', 'state' => 'Karnataka'];
        $this->postJson('/api/clients', $base + ['pincode' => '5600'])->assertStatus(422)->assertJsonValidationErrors('pincode');
        $this->postJson('/api/clients', array_merge($base, ['city' => '', 'pincode' => '560068']))->assertStatus(422)->assertJsonValidationErrors('city');

        $id = $this->postJson('/api/clients', $base + ['pincode' => '560 068'])->assertCreated()->json('id') ?? Client::latest('id')->value('id');
        $client = Client::findOrFail($id);
        $this->assertSame('560068', $client->pincode);
        $this->assertSame('No. 5, Hosur Road, Bengaluru, Karnataka, India - 560068', $client->address);

        // A status-only edit of an older client with no parts is not blocked.
        $old = Client::create(['client_code' => 'A01M', 'legal_name' => 'Old Co', 'company_name' => 'Old Co', 'client_type' => 'organization',
            'nationality' => 'India', 'country' => 'India', 'status' => 'Active', 'address' => 'Somewhere']);
        $this->putJson("/api/clients/{$old->id}", ['status' => 'Inactive'])->assertOk();
    }

    public function test_save_to_client_from_forms_rebuilds_the_address_and_form1_uses_the_parts(): void
    {
        $client = Client::create(['client_code' => 'B37M', 'legal_name' => 'Luri Energy Private Limited', 'company_name' => 'Luri Energy',
            'client_type' => 'organization', 'nationality' => 'India', 'country' => 'India', 'state' => 'Karnataka', 'status' => 'Active',
            'address' => '12, Lake View Road, Mysuru, Karnataka 570001']);
        $project = Project::create(['project_code' => 'B37M001INPRV', 'docket_number' => 'B37M001INPRV', 'client_id' => $client->id,
            'project_type' => 'Patent', 'project_name' => 'Electrolysis', 'patent_office_code' => 'IN', 'service_code' => 'PRV', 'status' => 'Open']);

        // Before any parts are saved, Form 1 reads the split old address.
        $applicant = app(\App\Services\Ipo\Form1DataBuilder::class)->build($project->load('client'), ['application_type' => 'ordinary', 'sub_type' => 'none', 'title' => 'Electrolysis', 'applicant_category' => 'small_entity', 'co_applicants' => [], 'inventors_same_as_applicants' => false, 'agent_user_ids' => [], 'convention' => [], 'declarations' => [], 'office' => null])['applicants'][0];
        $this->assertSame('Mysuru', $applicant['city']);
        $this->assertSame('570001', $applicant['pincode']);
        $this->assertSame('12', $applicant['address_house']);
        $this->assertSame('Lake View Road', $applicant['address_street']);

        $this->putJson("/api/projects/{$project->id}/ipo-forms/applicant", ['address_house' => '12', 'address_street' => 'Lake View Road', 'city' => 'Mysuru', 'pincode' => '570001'])->assertOk();
        $this->assertSame('12, Lake View Road, Mysuru, Karnataka, India - 570001', $client->fresh()->address);
    }

    public function test_split_command_dry_run_then_apply(): void
    {
        $client = Client::create(['client_code' => 'C01M', 'legal_name' => 'Split Co', 'company_name' => 'Split Co', 'client_type' => 'organization',
            'nationality' => 'India', 'country' => 'India', 'status' => 'Active', 'address' => '207, EGPI Arcadia, Banaswadi Main Road, Bengaluru, Karnataka 560033']);

        $this->artisan('clients:split-addresses', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($client->fresh()->city);

        $this->artisan('clients:split-addresses', ['--apply' => true])->assertSuccessful();
        $client = $client->fresh();
        $this->assertSame('Bengaluru', $client->city);
        $this->assertSame('560033', $client->pincode);
        $this->assertSame('Karnataka', $client->state);
        $this->assertSame('207, EGPI Arcadia, Banaswadi Main Road, Bengaluru, Karnataka 560033', $client->address);   // old line kept
        $this->assertDatabaseHas('audit_logs', ['action' => 'client_address_split', 'subject_id' => $client->id]);
    }
}
