<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DocketEvent;
use App\Models\Firm;
use App\Models\PatentApplication;
use App\Models\Project;
use App\Models\User;
use App\Support\FirmContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

class FirmOwnershipCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_mypl_portal_user_client_and_matter_flow_is_preserved(): void
    {
        $firm = Firm::query()->where('slug', 'legacy-firm')->sole();
        $portalUser = $this->user('client_admin', 'portal@example.test');
        $client = Client::query()->create([
            'client_code' => 'C99M',
            'company_name' => 'Portal Client',
            'legal_name' => 'Portal Client Private Limited',
            'client_type' => 'organization',
            'portal_user_id' => $portalUser->id,
            'portal_enabled' => true,
            'status' => 'Active',
        ]);
        $project = Project::query()->create([
            'project_code' => 'PRJ-2026-99999',
            'docket_number' => 'C99M001INPAT',
            'client_id' => $client->id,
            'project_type' => 'Patent',
            'project_name' => 'Portal Patent Matter',
            'status' => 'Active',
        ]);
        $application = PatentApplication::query()->create([
            'client_id' => $client->id,
            'application_number' => '202611111111',
            'title' => 'Portal Patent Matter',
        ]);
        $event = DocketEvent::query()->create([
            'project_id' => $project->id,
            'patent_application_id' => $application->id,
            'event_type' => 'filed',
            'event_date' => now()->toDateString(),
        ]);

        $this->assertSame($firm->id, $portalUser->fresh()->current_firm_id);
        $this->assertSame($firm->id, $client->firm_id);
        $this->assertSame($firm->id, $project->firm_id);
        $this->assertSame($firm->id, $application->firm_id);
        $this->assertSame($firm->id, $event->firm_id);
        $this->assertTrue($client->isVisibleToUser($portalUser));

        Sanctum::actingAs($portalUser);
        $this->getJson('/api/projects')->assertOk()->assertJsonFragment([
            'docket_number' => 'C99M001INPAT',
        ]);
    }

    public function test_current_context_assigns_new_users_and_clients_to_selected_firm(): void
    {
        $firm = $this->firm('second-firm');

        app(FirmContext::class)->run($firm, function () use ($firm): void {
            $user = $this->user('partner', 'partner@second-firm.test');
            $client = Client::query()->create([
                'client_code' => 'S01F',
                'company_name' => 'Second Firm Client',
                'status' => 'Active',
            ]);

            $this->assertSame($firm->id, $user->fresh()->current_firm_id);
            $this->assertDatabaseHas('firm_user', [
                'firm_id' => $firm->id,
                'user_id' => $user->id,
                'role' => 'partner',
            ]);
            $this->assertSame($firm->id, $client->firm_id);
        });
    }

    public function test_new_owned_record_requires_context_after_multiple_firms_exist(): void
    {
        $this->firm('second-firm');

        $this->expectException(LogicException::class);
        Client::query()->create([
            'client_code' => 'NOCTX',
            'company_name' => 'Unowned Client',
            'status' => 'Active',
        ]);
    }

    private function user(string $role, string $email): User
    {
        return User::query()->create([
            'name' => str($role)->headline()->toString(),
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'status' => 'Active',
        ]);
    }

    private function firm(string $slug): Firm
    {
        return Firm::query()->create([
            'name' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'status' => 'Active',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency' => 'USD',
        ]);
    }
}
