<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\IpRecord;
use App\Models\Project;
use App\Models\TrademarkApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrademarkCaseTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'partner', string $email = 'partner@tm.test'): User
    {
        return User::create([
            'name' => ucfirst($role).' User', 'email' => $email,
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'Active',
        ]);
    }

    private function client(): Client
    {
        return Client::create([
            'client_code' => '512M', 'client_type' => 'organization',
            'legal_name' => 'Brand Owner', 'company_name' => 'Brand Owner',
            'nationality' => 'India', 'status' => 'Active',
        ]);
    }

    private function payload(Client $client, string $service, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $client->id,
            'project_name' => 'MYPL word and logo',
            'project_type' => 'Trademark',
            'patent_office_code' => 'IN',
            'service_code' => $service,
            'trademark' => ['mark_text' => 'MYPL', 'mark_type' => 'Device', 'nice_classes' => [45]],
        ], $overrides);
    }

    public function test_tmf_case_creates_trademark_record_and_trademark_stages(): void
    {
        Sanctum::actingAs($this->user());
        $client = $this->client();

        $project = $this->postJson('/api/projects', $this->payload($client, 'TMF'))->assertCreated()->json();

        $this->assertSame('512M001INTMF', $project['docket_number']);
        $record = IpRecord::findOrFail(Project::findOrFail($project['id'])->ip_record_id);
        $this->assertSame('Trademark', $record->record_type);
        $this->assertSame('MYPL', $record->title);
        $application = TrademarkApplication::where('ip_record_id', $record->id)->firstOrFail();
        $this->assertSame('Device', $application->mark_type);
        $this->assertSame([45], $application->nice_classes);
        $this->assertNull(Project::find($project['id'])->patent_application_id);

        $stages = Project::find($project['id'])->stages()->orderBy('sequence_order')->pluck('stage_name')->all();
        $this->assertSame(config('trademark_lifecycle.templates.TMF.1'), $stages);
    }

    public function test_five_letter_service_codes_save_and_parse(): void
    {
        Sanctum::actingAs($this->user());
        $client = $this->client();

        $this->postJson('/api/projects', $this->payload($client, 'TMINF', ['trademark' => null]))
            ->assertCreated()->assertJsonPath('docket_number', '512M001INTMINF');

        $this->postJson('/api/projects', $this->payload($client, 'TMOR', [
            'record_mode' => 'existing', 'project_code' => '512M007INTMOR', 'docket_number' => '512M007INTMOR',
            'trademark' => null,
        ]))->assertCreated()->assertJsonPath('service_code', 'TMOR');

        $stages = Project::where('docket_number', '512M007INTMOR')->firstOrFail()
            ->stages()->orderBy('sequence_order')->pluck('stage_name')->all();
        $this->assertSame(config('trademark_lifecycle.templates.TMOR.1'), $stages);
    }

    public function test_filing_validation_rules(): void
    {
        Sanctum::actingAs($this->user());
        $client = $this->client();

        $this->postJson('/api/projects', $this->payload($client, 'TMF', ['trademark' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('trademark');
        $this->postJson('/api/projects', $this->payload($client, 'TMF', [
            'trademark' => ['mark_text' => 'MYPL', 'mark_type' => 'Device', 'nice_classes' => [46]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('trademark.nice_classes.0');
        $this->postJson('/api/projects', $this->payload($client, 'TMF', [
            'trademark' => ['mark_text' => 'MYPL', 'mark_type' => 'Device', 'nice_classes' => [9, 35]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('trademark.nice_classes');
        $this->postJson('/api/projects', $this->payload($client, 'TMC', [
            'trademark' => ['mark_text' => 'MYPL', 'mark_type' => 'Word', 'nice_classes' => [9]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('trademark.nice_classes');
        $this->postJson('/api/projects', $this->payload($client, 'CPT'))
            ->assertUnprocessable()->assertJsonValidationErrors('trademark');
    }

    public function test_follow_on_engagement_reuses_the_filing_record(): void
    {
        Sanctum::actingAs($this->user());
        $client = $this->client();

        $filing = $this->postJson('/api/projects', $this->payload($client, 'TMF', [
            'record_mode' => 'existing', 'project_code' => '512M003INTMF', 'docket_number' => '512M003INTMF',
        ]))->assertCreated()->json();
        $reply = $this->postJson('/api/projects', $this->payload($client, 'TME', [
            'record_mode' => 'existing', 'project_code' => '512M003INTME', 'docket_number' => '512M003INTME',
            'trademark' => null,
        ]))->assertCreated()->json();

        $this->assertSame(
            Project::find($filing['id'])->ip_record_id,
            Project::find($reply['id'])->ip_record_id,
        );
        $this->assertSame(1, IpRecord::where('record_type', 'Trademark')->count());
    }

    public function test_logo_upload_validation_and_stage_gate(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->user());
        $client = $this->client();
        $project = $this->postJson('/api/projects', $this->payload($client, 'TMF'))->assertCreated()->json();
        $filedStage = config('trademark_lifecycle.image_gate_stage');

        $this->postJson("/api/projects/{$project['id']}/stage", ['stage_name' => $filedStage])
            ->assertUnprocessable()->assertJsonPath('requires_mark_image', true);

        $this->postJson("/api/projects/{$project['id']}/trademark/mark-image", [
            'image' => UploadedFile::fake()->create('mark.pdf', 10, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->postJson("/api/projects/{$project['id']}/trademark/mark-image", [
            'image' => UploadedFile::fake()->image('mark.png')->size(6000),
        ])->assertUnprocessable()->assertJsonValidationErrors('image');

        $response = $this->postJson("/api/projects/{$project['id']}/trademark/mark-image", [
            'image' => UploadedFile::fake()->image('mark.png', 400, 400),
        ])->assertCreated();
        $path = $response->json('data.image_path');
        Storage::disk('local')->assertExists($path);
        $document = Document::where('storage_path', $path)->firstOrFail();
        $this->assertSame('Trademarks', $document->category);
        $this->assertSame($project['id'], $document->project_id);

        $this->postJson("/api/projects/{$project['id']}/stage", ['stage_name' => $filedStage])->assertOk();

        // Replacing keeps one Document with a second version.
        $this->postJson("/api/projects/{$project['id']}/trademark/mark-image", [
            'image' => UploadedFile::fake()->image('mark-v2.jpg', 400, 400),
        ])->assertCreated()->assertJsonPath('data.image_version', 2);
        $this->assertSame(1, Document::where('category', 'Trademarks')->count());
    }

    public function test_word_mark_needs_no_logo_to_pass_filing_stage(): void
    {
        Sanctum::actingAs($this->user());
        $client = $this->client();
        $project = $this->postJson('/api/projects', $this->payload($client, 'TMF', [
            'trademark' => ['mark_text' => 'MYPL', 'mark_type' => 'Word', 'nice_classes' => [45]],
        ]))->assertCreated()->json();

        $this->postJson("/api/projects/{$project['id']}/stage", [
            'stage_name' => config('trademark_lifecycle.image_gate_stage'),
        ])->assertOk();
    }

    public function test_client_users_cannot_upload_or_edit_marks(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->user());
        $client = $this->client();
        $project = $this->postJson('/api/projects', $this->payload($client, 'TMF'))->assertCreated()->json();

        Sanctum::actingAs($this->user('client', 'client@tm.test'));
        $this->postJson("/api/projects/{$project['id']}/trademark/mark-image", [
            'image' => UploadedFile::fake()->image('mark.png'),
        ])->assertForbidden();
        $this->putJson("/api/projects/{$project['id']}/trademark", [
            'mark_text' => 'HIJACK', 'mark_type' => 'Word', 'nice_classes' => [45],
        ])->assertForbidden();
    }
}
