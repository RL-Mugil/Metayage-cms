<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inventor;
use App\Models\IpoFormInput;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

class IpoForm1Test extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Project $project;

    private User $paralegal;

    private User $agent;

    private Inventor $inventor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::create([
            'client_code' => '397M', 'client_type' => 'organization',
            'legal_name' => 'Acme Robotics Private Limited', 'company_name' => 'Acme Robotics',
            'nationality' => 'India', 'country' => 'India', 'state' => 'Karnataka', 'status' => 'Active',
            'address_house' => '12', 'address_street' => 'MG Road', 'city' => 'Bengaluru', 'pincode' => '560001',
            'contact_email' => 'ip@acme.test', 'phone' => '9876543210',
        ]);
        $this->paralegal = $this->user('paralegal', 'para@firm.test');
        $this->project = Project::create([
            'project_code' => '397M001INPAT', 'docket_number' => '397M001INPAT', 'client_id' => $this->client->id,
            'project_type' => 'Patent', 'project_name' => 'Gripper', 'invention_title' => 'Adaptive Robotic Gripper',
            'patent_office_code' => 'IN', 'status' => 'Open', 'patent_engineer_id' => $this->paralegal->id,
        ]);

        $this->inventor = Inventor::create([
            'full_name' => 'KUMAR Ravi', 'nationality' => 'Indian',
            'country_of_residence' => 'India', 'address_house' => '4', 'address_street' => 'Lake View',
            'city' => 'Chennai', 'state' => 'Tamil Nadu', 'country' => 'India', 'pincode' => '600001',
        ]);
        $this->project->syncInventors([$this->inventor->id]);

        $this->agent = $this->user('partner', 'agent@firm.test', 'Arjun Mehta');
        $this->agent->update(['patent_agent_no' => 'IN/PA-1234', 'mobile' => '9000000001']);

        foreach (['name' => 'Metayage Private Limited', 'address' => '207 EGPI Arcadia, Bangalore', 'email' => 'filing@firm.test', 'mobile' => '9000000002'] as $key => $value) {
            DB::table('system_settings')->updateOrInsert(['key' => 'ipo_service_'.$key], ['value' => $value]);
        }
    }

    private function user(string $role, string $email, string $name = 'User'): User
    {
        return User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password'), 'role' => $role, 'status' => 'Active']);
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'application_type' => 'ordinary', 'sub_type' => 'none', 'title' => 'Adaptive Robotic Gripper',
            'applicant_category' => 'small_entity', 'co_applicants' => [], 'inventors_same_as_applicants' => false,
            'agent_user_ids' => [$this->agent->id], 'convention' => [],
            'declarations' => ['possession', 'assignee'],
        ], $overrides);
    }

    private function documentXml($response): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()) === true);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        return $xml;
    }

    public function test_prefill_resolves_office_from_state_and_lists_agents(): void
    {
        Sanctum::actingAs($this->paralegal);

        $data = $this->getJson("/api/projects/{$this->project->id}/ipo-forms/form1")->assertOk()->json();

        $this->assertSame('Chennai', $data['resolved_office']);
        $this->assertSame('state', $data['office_source']);
        $this->assertSame('ordinary', $data['input']['application_type']);
        $this->assertSame('other_than_natural', $data['input']['applicant_category']);
        $this->assertSame('IN/PA-1234', $data['agent_options'][0]['patent_agent_no']);
        $this->assertTrue($data['inventors'][0]['complete']);
        $this->assertContains('Select at least one patent agent (section 6).', $data['missing']);
    }

    public function test_generate_returns_docx_filled_from_case_data(): void
    {
        Sanctum::actingAs($this->paralegal);

        $response = $this->postJson("/api/projects/{$this->project->id}/ipo-forms/form1/generate", $this->input([
            'application_type' => 'convention',
            'convention' => [['country' => 'US', 'application_number' => '63/123,456', 'filing_date' => '2026-01-15', 'applicant_name' => 'Acme Inc', 'title' => 'Gripper']],
            'declarations' => ['possession', 'assignee', 'convention_first', 'convention_priority'],
        ]))->assertOk();

        $this->assertStringContainsString('wordprocessingml', $response->headers->get('Content-Type'));
        $xml = $this->documentXml($response);
        foreach ([
            'Acme Robotics Private Limited', 'Bengaluru', 'KUMAR Ravi', 'Lake View', 'IN/PA-1234', 'Arjun Mehta',
            'Convention (✓)', 'Ordinary ( )', 'Small Entity (✓)', '63/123,456', '15/01/2026', 'Adaptive Robotic Gripper',
            '(c) Name(s): KUMAR Ravi', 'The Patent Office, at Chennai', 'Metayage Private Limited', 'filing@firm.test',
        ] as $expected) {
            $this->assertStringContainsString(htmlspecialchars($expected, ENT_XML1 | ENT_QUOTES), $xml, "Missing: {$expected}");
        }
        $this->assertStringContainsString('☑ I am/We are in possession', $xml);
        $this->assertStringContainsString('☐ I am/we are the true &amp; first inventor(s).', $xml);
        $this->assertSame('small_entity', $this->client->fresh()->applicant_category);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ipo_form1_generated', 'subject_id' => $this->project->id]);
    }

    public function test_one_inventor_declaration_block_per_inventor(): void
    {
        $second = Inventor::create(['full_name' => 'Priya Nair']);
        $this->project->syncInventors([$this->inventor->id, $second->id]);
        Sanctum::actingAs($this->paralegal);

        $xml = $this->documentXml($this->postJson("/api/projects/{$this->project->id}/ipo-forms/form1/generate", $this->input())->assertOk());

        $this->assertStringContainsString('(c) Name(s): KUMAR Ravi', $xml);
        $this->assertStringContainsString('(c) Name(s): Priya Nair', $xml);
        $this->assertSame(3, substr_count($xml, '(a) Date'));   // two inventors + the convention-country block
    }

    public function test_save_persists_encrypted_input_and_audits(): void
    {
        Sanctum::actingAs($this->paralegal);

        $this->putJson("/api/projects/{$this->project->id}/ipo-forms/form1", $this->input([
            'application_type' => 'pct_np', 'pct' => ['application_number' => 'PCT/IN2026/050001', 'filing_date' => '2026-02-01'],
            'co_applicants' => [['name' => 'Second Applicant LLP', 'nationality' => 'Indian', 'city' => 'Pune', 'state' => 'Maharashtra', 'country' => 'India', 'pincode' => '411001', 'email' => 'co@llp.test']],
        ]))->assertOk()->assertJsonPath('input.pct.application_number', 'PCT/IN2026/050001');

        $saved = IpoFormInput::where('project_id', $this->project->id)->firstOrFail();
        $this->assertSame('Second Applicant LLP', $saved->data['co_applicants'][0]['name']);
        $this->assertStringNotContainsString('Second Applicant LLP', DB::table('ipo_form_inputs')->value('data'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'ipo_form1_saved', 'subject_id' => $this->project->id]);
    }

    public function test_foreign_applicant_uses_chosen_office(): void
    {
        $this->client->update(['country' => 'United States', 'nationality' => 'United States', 'state' => null]);
        Sanctum::actingAs($this->paralegal);

        $data = $this->getJson("/api/projects/{$this->project->id}/ipo-forms/form1")->assertOk()->json();
        $this->assertNull($data['resolved_office']);

        $xml = $this->documentXml($this->postJson("/api/projects/{$this->project->id}/ipo-forms/form1/generate", $this->input(['office' => 'Delhi']))->assertOk());
        $this->assertStringContainsString('The Patent Office, at Delhi', $xml);
    }

    public function test_paralegal_can_complete_applicant_and_inventor_details(): void
    {
        Sanctum::actingAs($this->paralegal);

        $this->putJson("/api/projects/{$this->project->id}/ipo-forms/applicant", ['city' => 'Mysuru', 'pincode' => '570001'])->assertOk();
        $this->assertSame('Mysuru', $this->client->fresh()->city);

        $this->putJson("/api/inventors/{$this->inventor->id}", ['full_name' => 'KUMAR Ravi', 'city' => 'Madurai', 'age' => 41])->assertOk();
        $this->assertSame('Madurai', $this->inventor->fresh()->city);
        $this->assertSame('41', (string) $this->inventor->fresh()->age);
    }

    public function test_unauthorised_roles_cannot_prepare_form1(): void
    {
        $url = "/api/projects/{$this->project->id}/ipo-forms/form1";
        $clientUser = $this->user('client', 'portal@acme.test');
        $this->client->update(['portal_user_id' => $clientUser->id]);

        foreach ([
            $this->user('paralegal', 'unassigned@firm.test'),
            $this->user('finance', 'fin@firm.test'),
            $this->user('hr', 'hr@firm.test'),
            $this->user('inventor', 'inv@acme.test'),
            $clientUser,
        ] as $user) {
            Sanctum::actingAs($user);
            $this->getJson($url)->assertForbidden();
            $this->postJson($url.'/generate', $this->input())->assertForbidden();
        }
    }

    public function test_only_admins_manage_ipo_settings(): void
    {
        Sanctum::actingAs($this->paralegal);
        $this->getJson('/api/settings/ipo')->assertForbidden();
        $this->putJson("/api/settings/ipo/agents/{$this->paralegal->id}", ['patent_agent_no' => 'IN/PA-9'])->assertForbidden();

        Sanctum::actingAs($this->agent);
        $this->putJson('/api/settings/ipo/service', ['name' => 'Metayage', 'email' => 'new@firm.test'])
            ->assertOk()->assertJsonPath('service.email', 'new@firm.test');
        $this->putJson("/api/settings/ipo/agents/{$this->paralegal->id}", ['patent_agent_no' => 'in/pa-5678', 'mobile' => '9111111111'])->assertOk();
        $this->assertSame('IN/PA-5678', $this->paralegal->fresh()->patent_agent_no);
        $this->putJson("/api/settings/ipo/agents/{$this->paralegal->id}", ['patent_agent_no' => 'IN/PA-1234'])->assertStatus(422);
    }
}
