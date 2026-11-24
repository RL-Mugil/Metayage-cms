<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ComplianceItem;
use App\Models\PatentInvoiceIn;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The access model (App\Support\DataAccess): Patent Analysts / Engineers / Attorneys see finance,
 * pending payments, compliance and reports only for cases where they are Client Manager or
 * Secondary CM; Director, Accountant, HR and System Admin see all; a paralegal sees finance
 * only when on Settings → Financial access.
 */
class AccessScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $analyst;

    /** @var array<string, Project> */
    private array $cases = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyst = $this->user('associate', 'analyst@scope.test');
        $other = $this->user('associate', 'other@scope.test');
        $client = Client::create(['client_code' => '501M', 'client_type' => 'organization', 'legal_name' => 'Scope Co', 'company_name' => 'Scope Co',
            'nationality' => 'India', 'status' => 'Active']);
        foreach (['CM' => ['assigned_manager_id' => $this->analyst->id], 'SCM' => ['assigned_manager_id' => $other->id, 'secondary_manager_id' => $this->analyst->id],
            'NONE' => ['assigned_manager_id' => $other->id, 'patent_engineer_id' => $this->analyst->id]] as $key => $team) {
            $n = count($this->cases) + 1;
            $case = Project::create(['project_code' => "501M00{$n}INPRV", 'docket_number' => "501M00{$n}INPRV", 'client_id' => $client->id,
                'project_type' => 'Patent', 'project_name' => "Case {$key}", 'patent_office_code' => 'IN', 'service_code' => 'PRV', 'status' => 'Open', ...$team]);
            $this->cases[$key] = $case;
            PatentInvoiceIn::create(['type' => 'invoice', 'status' => 'Sent', 'source' => 'manual', 'client_id' => $client->id, 'project_id' => $case->id,
                'invoice_uin' => "501M00{$n}INPRV/1", 'invoice_amount' => 1000 * $n, 'total_bcy' => 1000 * $n, 'balance_due' => 1000 * $n, 'exchange_rate' => 1,
                'payment_status' => 'Pending', 'invoice_date' => now()->subDays(20)->toDateString(), 'due_date' => now()->addDays(10)->toDateString(), 'currency' => 'INR']);
            ComplianceItem::create(['client_id' => $client->id, 'project_id' => $case->id, 'matter' => "Case {$key}", 'type' => 'Deadline',
                'jurisdiction' => 'IN', 'deadline' => now()->addDays(15)->toDateString(), 'action_required' => 'File', 'status' => 'On Track', 'source_type' => 'manual']);
        }
    }

    private function user(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role).' '.$email, 'email' => $email, 'password' => bcrypt('x'), 'role' => $role, 'status' => 'Active']);
    }

    public function test_analyst_sees_only_cm_and_scm_cases_in_finance_pending_payments_and_compliance(): void
    {
        Sanctum::actingAs($this->analyst);

        $pending = collect($this->getJson('/api/pending-payments')->assertOk()->json('data'))->pluck('project_id')->sort()->values()->all();
        $this->assertSame([$this->cases['CM']->id, $this->cases['SCM']->id], $pending);

        $register = collect($this->getJson('/api/patent-invoices/in')->assertOk()->json('data'))->pluck('project_id')->sort()->values()->all();
        $this->assertSame([$this->cases['CM']->id, $this->cases['SCM']->id], $register);

        $this->assertEquals(3000, $this->getJson('/api/financial/stats')->assertOk()->json('total_outstanding'));   // 1000 + 2000, not the 3000 on case NONE

        $compliance = collect($this->getJson('/api/compliance')->assertOk()->json('data'))->pluck('project.id')->sort()->values()->all();
        $this->assertSame([$this->cases['CM']->id, $this->cases['SCM']->id], $compliance);

        // View + follow-up only.
        $invoice = PatentInvoiceIn::where('project_id', $this->cases['CM']->id)->first();
        $this->postJson("/api/pending-payments/{$invoice->id}/mark-paid", ['amount' => 100, 'date' => now()->toDateString(), 'mode' => 'UPI'])->assertForbidden();
        $other = PatentInvoiceIn::where('project_id', $this->cases['NONE']->id)->first();
        $this->postJson("/api/pending-payments/{$other->id}/status-note", ['status_note' => 'Chasing'])->assertForbidden();
        $this->postJson("/api/pending-payments/{$invoice->id}/status-note", ['status_note' => 'Chasing'])->assertOk();

        // Case reports now open to analysts, scoped to their cases.
        $rows = $this->postJson('/api/reports/generate', ['type' => 'payment-collection', 'format' => 'CSV'])->assertOk()->json('rows');
        $this->assertCount(2, $rows);
    }

    public function test_paralegal_needs_the_financial_access_list_and_only_system_admin_sets_it(): void
    {
        $paralegal = $this->user('paralegal', 'para@scope.test');
        Sanctum::actingAs($paralegal);
        $this->getJson('/api/pending-payments')->assertForbidden();
        $this->getJson('/api/compliance')->assertOk();   // sees all cases
        $this->assertCount(3, $this->getJson('/api/compliance')->json('data'));
        $this->putJson('/api/settings/finance-access', ['user_ids' => [$paralegal->id]])->assertForbidden();

        Sanctum::actingAs($this->user('super_admin', 'sa@scope.test'));
        $this->putJson('/api/settings/finance-access', ['user_ids' => [$paralegal->id]])->assertOk();
        \App\Support\DataAccess::forgetFinanceList();

        Sanctum::actingAs($paralegal);
        $this->assertCount(3, $this->getJson('/api/pending-payments')->assertOk()->json('data'));

        Sanctum::actingAs($this->user('hr', 'hr@scope.test'));
        $this->assertCount(3, $this->getJson('/api/patent-invoices/in')->assertOk()->json('data'));
    }
}
