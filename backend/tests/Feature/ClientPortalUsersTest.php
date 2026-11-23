<?php

namespace Tests\Feature;

use App\Mail\PortalInviteMail;
use App\Models\Client;
use App\Models\Inventor;
use App\Models\Invoice;
use App\Models\User;
use App\Services\ClientPortalUsers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Portal logins per client: made with the client, from the Users tab, or on the Client Portal page. */
class ClientPortalUsersTest extends TestCase
{
    use RefreshDatabase;

    /** An inventor's Form 1 address, required whenever an Inventor login is added. */
    private const ADDRESS = [
        'nationality' => 'Indian', 'gender' => 'Male', 'age' => '38', 'address_house' => '12', 'address_street' => 'MG Road',
        'city' => 'Bengaluru', 'state' => 'Karnataka', 'country' => 'India', 'pincode' => '560001',
    ];

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->partner = $this->user('partner', 'partner@firm.test');
        Sanctum::actingAs($this->partner);
    }

    private function user(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $email, 'password' => bcrypt('password'), 'role' => $role, 'status' => 'Active']);
    }

    private function createClientWithUsers(): Client
    {
        $id = $this->postJson('/api/clients', [
            'legal_name' => 'Acme Robotics', 'client_type' => 'organization', 'country' => 'India', 'status' => 'Active',
            'accounts_person' => 'Kavya Rao',
            'accounts_portal' => ['email' => 'accounts@acme.test', 'phone' => '9000000001', 'password' => 'Secret123'],
            'portal_users' => [
                ['designation' => 'Manager', 'name' => 'Ravi Kumar', 'email' => 'ravi@acme.test', 'password' => 'Secret123'],
                ['designation' => 'CTO', 'name' => 'Meena S', 'email' => 'cto@acme.test', 'password' => 'Secret123'],
                ['designation' => 'Inventor', 'name' => 'Arun P', 'email' => 'arun@acme.test', 'password' => 'Secret123', 'inventor' => self::ADDRESS],
            ],
        ])->assertCreated()->json('id');

        return Client::findOrFail($id);
    }

    public function test_client_is_created_with_its_portal_users_and_access_follows_designation(): void
    {
        $client = $this->createClientWithUsers();

        $this->assertSame('client_finance', User::where('email', 'accounts@acme.test')->value('role'));
        $this->assertSame('client_admin', User::where('email', 'ravi@acme.test')->value('role'));
        $this->assertSame('client', User::where('email', 'cto@acme.test')->value('role'));
        $this->assertSame('inventor', User::where('email', 'arun@acme.test')->value('role'));
        $this->assertTrue((bool) $client->portal_enabled);
        // The Manager is the primary login, even though the Accounts Person was created first.
        $this->assertSame(User::where('email', 'ravi@acme.test')->value('id'), $client->fresh()->portal_user_id);

        // The inventor is in the client's Inventors list, with the login linked, and not a client contact.
        $inventor = Inventor::where('client_id', $client->id)->where('email', 'arun@acme.test')->firstOrFail();
        $this->assertSame(User::where('email', 'arun@acme.test')->value('id'), $inventor->user_id);
        // Its address went into the inventor record, so Form 1 has every particular.
        $this->assertSame('MG Road', $inventor->address_street);
        $this->assertSame('560001', $inventor->pincode);
        $this->assertSame('Male', $inventor->gender);
        $this->assertTrue($inventor->isComplete());
        $this->assertDatabaseMissing('client_contacts', ['email' => 'arun@acme.test']);
        $this->assertDatabaseHas('client_contacts', ['email' => 'cto@acme.test', 'designation' => 'CTO', 'client_id' => $client->id]);

        // The Users tab and the Client Portal page read the same list.
        $rows = collect($this->getJson("/api/portal/clients/{$client->id}/users")->assertOk()->json())->keyBy('email');
        $this->assertCount(4, $rows);
        $this->assertSame('Finance', $rows['accounts@acme.test']['access']);
        $this->assertSame('Accounts Person', $rows['accounts@acme.test']['designation']);
        $this->assertSame('Admin', $rows['ravi@acme.test']['access']);
        $this->assertSame('Inventor', $rows['arun@acme.test']['designation']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'portal_user_added', 'subject_id' => $client->id]);
    }

    public function test_users_tab_adds_resets_and_removes_logins(): void
    {
        $client = $this->createClientWithUsers();

        $this->postJson("/api/portal/clients/{$client->id}/users", ['designation' => 'CFO', 'name' => 'Priya', 'email' => 'cfo@acme.test', 'password' => 'Secret123'])
            ->assertCreated()->assertJsonPath('user.access', 'Finance');
        $this->postJson("/api/portal/clients/{$client->id}/users", ['designation' => 'CFO', 'name' => 'Dup', 'email' => 'cfo@acme.test', 'password' => 'Secret123'])
            ->assertStatus(422);
        $this->postJson("/api/portal/clients/{$client->id}/users", ['designation' => 'Other', 'name' => 'Staff', 'email' => 'partner@firm.test', 'password' => 'Secret123'])
            ->assertStatus(422);

        $inventorLogin = User::where('email', 'arun@acme.test')->firstOrFail();
        $this->postJson("/api/portal/clients/{$client->id}/users/{$inventorLogin->id}/reset-password", ['password' => 'Another123'])->assertOk();
        $this->deleteJson("/api/portal/clients/{$client->id}/users/{$inventorLogin->id}")->assertOk();
        $this->assertNull(Inventor::where('email', 'arun@acme.test')->value('user_id'));   // the record stays, without a login

        $this->deleteJson("/api/portal/clients/{$client->id}/users/{$client->fresh()->portal_user_id}")->assertStatus(422);   // primary
    }

    public function test_an_inventor_login_needs_the_form_1_address(): void
    {
        $client = Client::create(['client_code' => 'C13M', 'legal_name' => 'Eta', 'company_name' => 'Eta', 'client_type' => 'organization', 'nationality' => 'India', 'status' => 'Active']);
        $url = "/api/portal/clients/{$client->id}/users";
        $base = ['designation' => 'Inventor', 'name' => 'RAO Kiran', 'email' => 'kiran@eta.test', 'password' => 'Secret123'];

        $this->postJson($url, $base)->assertStatus(422)
            ->assertJsonValidationErrors(['inventor.nationality', 'inventor.city', 'inventor.country', 'inventor.pincode', 'inventor.address_house']);
        $this->postJson($url, $base + ['inventor' => array_diff_key(self::ADDRESS, ['state' => 1])])
            ->assertStatus(422)->assertJsonValidationErrors('inventor.state');   // an Indian address needs its state
        $this->assertDatabaseMissing('users', ['email' => 'kiran@eta.test']);
        $this->assertDatabaseMissing('inventors', ['email' => 'kiran@eta.test']);

        $this->postJson($url, $base + ['inventor' => self::ADDRESS])->assertCreated();
        $this->assertTrue(Inventor::where('email', 'kiran@eta.test')->firstOrFail()->isComplete());

        // The New Client form checks it before anything is saved.
        $this->postJson('/api/clients', [
            'legal_name' => 'Theta', 'client_type' => 'organization', 'country' => 'India', 'status' => 'Active',
            'portal_users' => [['designation' => 'Inventor', 'name' => 'X', 'email' => 'x@theta.test', 'password' => 'Secret123']],
        ])->assertStatus(422)->assertJsonValidationErrors('portal_users.0.inventor.city');
        $this->assertDatabaseMissing('clients', ['legal_name' => 'Theta']);
    }

    public function test_inventors_tab_picks_a_portal_user_as_inventor(): void
    {
        $client = $this->createClientWithUsers();
        $cto = User::where('email', 'cto@acme.test')->firstOrFail();

        $id = $this->postJson('/api/inventors', self::ADDRESS + ['client_id' => $client->id, 'full_name' => 'S Meena', 'user_id' => $cto->id])
            ->assertCreated()->assertJsonPath('email', 'cto@acme.test')->assertJsonPath('has_portal_access', true)->json('id');
        $this->assertSame($cto->id, Inventor::findOrFail($id)->user_id);
        $this->assertSame('client', $cto->fresh()->role);   // keeps their own access
        $this->assertSame(4, User::where('email', 'like', '%@acme.test')->count());   // no new login

        // Listed once in the Users tab, marked as an inventor; can't be picked twice.
        $rows = collect($this->getJson("/api/portal/clients/{$client->id}/users")->assertOk()->json());
        $this->assertCount(1, $rows->where('email', 'cto@acme.test'));
        $this->assertTrue($rows->firstWhere('email', 'cto@acme.test')['is_inventor']);
        $this->postJson('/api/inventors', self::ADDRESS + ['client_id' => $client->id, 'full_name' => 'Again', 'user_id' => $cto->id])
            ->assertStatus(422)->assertJsonValidationErrors('user_id');

        // Another client's user cannot be picked.
        $other = Client::create(['client_code' => 'C14M', 'legal_name' => 'Iota', 'company_name' => 'Iota', 'client_type' => 'organization', 'nationality' => 'India', 'status' => 'Active']);
        $this->postJson('/api/inventors', self::ADDRESS + ['client_id' => $other->id, 'full_name' => 'X', 'user_id' => User::where('email', 'ravi@acme.test')->value('id')])
            ->assertStatus(422)->assertJsonValidationErrors('user_id');

        // Deleting the inventor record leaves the CTO's login active.
        $this->deleteJson("/api/inventors/{$id}")->assertOk();
        $this->assertSame('Active', $cto->fresh()->status);
    }

    public function test_inventors_tab_creates_a_login_for_a_new_inventor(): void
    {
        $client = Client::create(['client_code' => 'C15M', 'legal_name' => 'Kappa', 'company_name' => 'Kappa', 'client_type' => 'organization', 'nationality' => 'India', 'status' => 'Active']);
        $new = self::ADDRESS + ['client_id' => $client->id, 'full_name' => 'NAIR Priya', 'email' => 'priya@kappa.test', 'portal_password' => 'Secret123'];

        $this->postJson('/api/inventors', array_diff_key($new, ['email' => 1]))->assertStatus(422)->assertJsonValidationErrors('email');
        $id = $this->postJson('/api/inventors', $new)->assertCreated()->assertJsonPath('has_portal_access', true)->json('id');
        $login = User::where('email', 'priya@kappa.test')->firstOrFail();
        $this->assertSame('inventor', $login->role);
        $this->assertSame($login->id, Inventor::findOrFail($id)->user_id);
        $this->assertTrue(collect($this->getJson("/api/portal/clients/{$client->id}/users")->json())->contains('email', 'priya@kappa.test'));
        Mail::assertNothingSent();   // invite emails are paused

        // The email already has a login: pick that user instead.
        $this->postJson('/api/inventors', array_merge($new, ['full_name' => 'Dup']))->assertStatus(422)->assertJsonValidationErrors('email');

        // Staff who cannot create logins still add the inventor, but not a login.
        Sanctum::actingAs($this->user('paralegal', 'para2@firm.test'));
        $this->postJson('/api/inventors', array_merge($new, ['email' => 'kiran@kappa.test', 'full_name' => 'RAO Kiran']))->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'kiran@kappa.test']);
        $this->postJson('/api/inventors', array_diff_key(array_merge($new, ['email' => 'kiran@kappa.test', 'full_name' => 'RAO Kiran']), ['portal_password' => 1]))
            ->assertCreated()->assertJsonPath('has_portal_access', false);
    }

    public function test_only_portal_managers_create_logins(): void
    {
        Sanctum::actingAs($this->user('galvanizer', 'galv@firm.test'));
        $this->postJson('/api/clients', [
            'legal_name' => 'Beta', 'client_type' => 'organization', 'country' => 'India', 'circle' => 'A',
            'portal_users' => [['designation' => 'Manager', 'name' => 'X', 'email' => 'x@beta.test', 'password' => 'Secret123']],
        ])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'x@beta.test']);

        $client = Client::create(['client_code' => 'C10M', 'legal_name' => 'Gamma', 'company_name' => 'Gamma', 'client_type' => 'organization', 'nationality' => 'India', 'status' => 'Active']);
        Sanctum::actingAs($this->user('paralegal', 'para@firm.test'));
        $this->postJson("/api/portal/clients/{$client->id}/users", ['designation' => 'CTO', 'name' => 'Y', 'email' => 'y@gamma.test', 'password' => 'Secret123'])->assertForbidden();
    }

    public function test_invite_emails_wait_while_paused(): void
    {
        $this->assertTrue(ClientPortalUsers::emailsPaused());   // paused until turned on
        $this->createClientWithUsers();
        Mail::assertNothingSent();

        $this->putJson('/api/portal/settings', ['emails_paused' => false])->assertOk()->assertJsonPath('emails_paused', false);
        $client = Client::create(['client_code' => 'C11M', 'legal_name' => 'Delta', 'company_name' => 'Delta', 'client_type' => 'organization', 'nationality' => 'India', 'status' => 'Active']);
        $this->postJson("/api/portal/clients/{$client->id}/users", ['designation' => 'Manager', 'name' => 'Z', 'email' => 'z@delta.test', 'password' => 'Secret123'])->assertCreated();
        Mail::assertSent(PortalInviteMail::class, fn (PortalInviteMail $mail) => $mail->hasTo('z@delta.test'));

        Sanctum::actingAs($this->user('associate', 'assoc@firm.test'));
        $this->putJson('/api/portal/settings', ['emails_paused' => true])->assertForbidden();
    }

    public function test_finance_login_sees_invoices_but_not_cases_or_other_clients(): void
    {
        $client = $this->createClientWithUsers();
        $other = Client::create(['client_code' => 'C12M', 'legal_name' => 'Other', 'company_name' => 'Other', 'client_type' => 'organization', 'nationality' => 'India', 'status' => 'Active']);
        Sanctum::actingAs(User::where('email', 'accounts@acme.test')->firstOrFail());

        $this->getJson('/api/financial/invoices')->assertOk();
        $this->getJson("/api/clients/{$client->id}/detail")->assertForbidden();
        $this->getJson("/api/clients/{$other->id}/detail")->assertForbidden();
        $this->getJson('/api/users')->assertForbidden();
    }
}
