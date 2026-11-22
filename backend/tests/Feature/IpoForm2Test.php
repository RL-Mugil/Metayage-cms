<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

class IpoForm2Test extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Project $project;

    private User $paralegal;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::create([
            'client_code' => '397M', 'client_type' => 'organization',
            'legal_name' => 'Acme Robotics Private Limited', 'company_name' => 'Acme Robotics',
            'nationality' => 'India', 'country' => 'India', 'state' => 'Karnataka', 'status' => 'Active',
            'address_house' => '12', 'address_street' => 'MG Road', 'city' => 'Bengaluru', 'pincode' => '560001',
        ]);
        $this->paralegal = $this->user('paralegal', 'para@firm.test');
        $this->project = $this->project('397M001INCPT', 'CPT');
        $this->agent = $this->user('partner', 'agent@firm.test', 'Arjun Mehta');
        $this->agent->update(['patent_agent_no' => 'IN/PA-1234']);
    }

    private function user(string $role, string $email, string $name = 'User'): User
    {
        return User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password'), 'role' => $role, 'status' => 'Active']);
    }

    private function project(string $docket, string $service): Project
    {
        return Project::create([
            'project_code' => $docket, 'docket_number' => $docket, 'client_id' => $this->client->id,
            'project_type' => 'Patent', 'project_name' => 'Gripper', 'invention_title' => 'Adaptive Robotic Gripper',
            'patent_office_code' => 'IN', 'service_code' => $service, 'status' => 'Open', 'patent_engineer_id' => $this->paralegal->id,
        ]);
    }

    private function documentXml($response): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()) === true);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        return $xml;
    }

    public function test_complete_specification_is_filled_from_the_case(): void
    {
        Sanctum::actingAs($this->paralegal);
        // The agent is the one chosen in Form 1's saved input.
        $this->putJson("/api/projects/{$this->project->id}/ipo-forms/form1", [
            'application_type' => 'ordinary', 'sub_type' => 'none', 'applicant_category' => 'other_than_natural',
            'agent_user_ids' => [$this->agent->id],
        ])->assertOk();

        $response = $this->postJson("/api/projects/{$this->project->id}/ipo-forms/form2/generate", ['specification' => 'complete'])->assertOk();
        $this->assertStringContainsString('Form2_397M001INCPT.docx', $response->headers->get('Content-Disposition'));

        $xml = $this->documentXml($response);
        foreach ([
            'FORM 2', 'Adaptive Robotic Gripper', '(a) NAME: Acme Robotics Private Limited', '(b) NATIONALITY: India',
            '(c) ADDRESS: 12, MG Road, Bengaluru, Karnataka - 560001, India', 'COMPLETE SPECIFICATION',
            'particularly describes the invention and the manner in which it is to be performed',
            'We claim:', 'Arjun Mehta (IN/PA-1234)', 'ABSTRACT', 'SUMMARY OF THE INVENTION',
        ] as $expected) {
            $this->assertStringContainsString(htmlspecialchars($expected, ENT_XML1 | ENT_QUOTES), $xml, "Missing: {$expected}");
        }
        $this->assertStringContainsString('<w:strike/>', $xml);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ipo_form2_generated', 'subject_id' => $this->project->id]);
    }

    public function test_provisional_case_defaults_to_a_provisional_specification(): void
    {
        $provisional = $this->project('397M002INPRV', 'PRV');
        Sanctum::actingAs($this->paralegal);

        $xml = $this->documentXml($this->postJson("/api/projects/{$provisional->id}/ipo-forms/form2/generate")->assertOk());

        $this->assertStringContainsString('The following specification describes the invention.', $xml);
        $this->assertStringContainsString('PROVISIONAL', $xml);
        $this->assertStringNotContainsString('We claim:', $xml);
        $this->assertStringNotContainsString('[Insert abstract', $xml);
        $this->assertStringNotContainsString('SUMMARY OF THE INVENTION', $xml);
    }

    public function test_unknown_specification_type_is_rejected(): void
    {
        Sanctum::actingAs($this->paralegal);

        $this->postJson("/api/projects/{$this->project->id}/ipo-forms/form2/generate", ['specification' => 'draft'])->assertStatus(422);
    }

    public function test_unauthorised_roles_cannot_generate_form2(): void
    {
        $url = "/api/projects/{$this->project->id}/ipo-forms/form2/generate";
        foreach ([
            $this->user('paralegal', 'unassigned@firm.test'),
            $this->user('finance', 'fin@firm.test'),
            $this->user('client', 'portal@acme.test'),
        ] as $user) {
            Sanctum::actingAs($user);
            $this->postJson($url, ['specification' => 'complete'])->assertForbidden();
        }
    }
}
