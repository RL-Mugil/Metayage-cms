<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use App\Policies\DocumentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoped_roles_cannot_read_documents_outside_their_assignment(): void
    {
        $manager = $this->user('manager', 'manager-doc@example.test');
        $paralegal = $this->user('paralegal', 'paralegal-doc@example.test');
        $finance = $this->user('finance', 'finance-doc@example.test');
        $client = Client::query()->create([
            'client_code' => 'DOC1', 'company_name' => 'Document Client',
            'account_manager_id' => $manager->id, 'status' => 'Active',
        ]);
        $project = Project::query()->create([
            'project_code' => 'PRJ-2026-70001', 'docket_number' => 'DOC1001INPAT',
            'client_id' => $client->id, 'project_name' => 'Restricted Matter',
            'project_type' => 'Patent', 'status' => 'Active',
            'assigned_manager_id' => $manager->id,
        ]);
        $document = Document::query()->create([
            'project_id' => $project->id, 'client_id' => $client->id,
            'file_name' => 'secret.pdf', 'storage_path' => 'documents/Patents/secret.pdf',
            'file_type' => 'application/pdf', 'file_size' => 100,
            'category' => 'Patents', 'current_version' => 1,
            'uploaded_by_id' => $manager->id, 'status' => 'Draft',
        ]);

        $policy = app(DocumentPolicy::class);
        $this->assertFalse($policy->view($paralegal, $document));
        $this->assertFalse($policy->view($finance, $document));
        $this->assertTrue($policy->view($manager, $document));
    }

    private function user(string $role, string $email): User
    {
        return User::query()->create([
            'name' => str($role)->headline()->toString(), 'email' => $email,
            'password' => 'password', 'role' => $role, 'status' => 'Active',
        ]);
    }
}
