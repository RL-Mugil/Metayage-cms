<?php

namespace Tests\Feature;

use App\Ai\ToolRegistry;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\PatentInvoiceIn;
use App\Models\Project;
use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The extended Claude connector: report files and signing, finance, cases / team, my HR, prompts. */
class McpToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $analyst;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->user('super_admin', 'sa@tools.test');
        $this->analyst = $this->user('associate', 'an@tools.test');
        $this->client = Client::create(['client_code' => '971M', 'client_type' => 'organization', 'legal_name' => 'Tools Co', 'company_name' => 'Tools Co',
            'nationality' => 'India', 'status' => 'Active', 'contact_email' => 'ap@tools.example']);
        Sanctum::actingAs($this->admin);
        foreach (['971M001INPRV', '971M002INPRV'] as $docket) {
            $this->postJson('/api/projects', ['record_mode' => 'existing', 'project_code' => $docket, 'docket_number' => $docket, 'client_id' => $this->client->id,
                'project_name' => "Case {$docket}", 'project_type' => 'Patent', 'patent_office_code' => 'IN', 'service_code' => 'PRV'])->assertCreated();
        }
        Project::where('docket_number', '971M001INPRV')->update(['assigned_manager_id' => $this->analyst->id, 'hard_deadline' => now()->addDays(5)->toDateString()]);

        // The firm signatory (as for payslips).
        $sig = Employee::create(['user_id' => $this->admin->id, 'employee_code' => 'SIG1', 'full_name' => 'Mugilvannan L', 'work_email' => 'sa@tools.test',
            'employment_status' => 'Active', 'date_of_joining' => now()->subYear(), 'base_pay_monthly' => 0, 'exclude_from_payroll' => true]);
        $sig->setInternalSignaturePin('2580');
        $sig->save();
        foreach (['payroll_signatory_user_id' => (string) $this->admin->id, 'payroll_signatory_name' => 'Mugilvannan L', 'payroll_signatory_designation' => 'AI Integration Engineer'] as $k => $v) {
            DB::table('system_settings')->updateOrInsert(['key' => $k], ['value' => $v, 'updated_at' => now()]);
        }
    }

    private function user(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role).' '.$email, 'email' => $email, 'password' => bcrypt('x'), 'role' => $role, 'status' => 'Active']);
    }

    private function tool(User $user, string $tool, array $args = []): array
    {
        return app(ToolRegistry::class)->call($user, $tool, $args, 'mcp');
    }

    private function download(string $url)
    {
        $this->app['auth']->forgetGuards();

        return $this->get(parse_url($url, PHP_URL_PATH));
    }

    private function invoice(string $docket, float $amount, string $due): PatentInvoiceIn
    {
        $case = Project::where('docket_number', $docket)->firstOrFail();

        return PatentInvoiceIn::create(['type' => 'invoice', 'status' => 'Sent', 'project_id' => $case->id, 'client_id' => $this->client->id,
            'docket_number' => $docket, 'invoice_uin' => $docket.'-INV', 'invoice_date' => now()->subDays(40)->toDateString(), 'due_date' => $due,
            'invoice_amount' => $amount, 'balance_due' => $amount, 'currency' => 'INR', 'payment_status' => 'Pending', 'client_name' => 'Tools Co']);
    }

    public function test_generate_report_files_links_and_scope(): void
    {
        $res = $this->tool($this->analyst, 'generate_report', ['type' => 'matter-status', 'format' => 'csv']);
        $this->assertArrayNotHasKey('error', $res, json_encode($res));
        $this->assertSame('UNSIGNED DRAFT', $res['status']);
        $export = ReportExport::findOrFail($res['export_id']);
        $this->assertSame('claude', $export->filters['source']);
        $this->assertSame((int) $this->analyst->id, (int) $export->generated_by_id);

        $csv = $this->download($res['download_url'])->assertOk();
        $body = $csv->streamedContent() ?: $csv->getContent();
        $this->assertStringContainsString('971M001INPRV', $body);
        $this->assertStringNotContainsString('971M002INPRV', $body);        // not the analyst's case
        $this->assertStringContainsString('UNSIGNED DRAFT', $body);
        $this->assertDatabaseHas('audit_logs', ['action' => 'claude_file_download', 'user_id' => $this->analyst->id]);

        foreach (['pdf' => '%PDF', 'xlsx' => 'PK'] as $format => $magic) {
            $r = $this->tool($this->admin, 'generate_report', ['type' => 'matter-status', 'format' => $format]);
            $this->assertStringStartsWith($magic, (string) $this->download($r['download_url'])->assertOk()->getContent());
        }

        // Links expire after 30 minutes; unknown tokens are 404.
        $this->travel(31)->minutes();
        $this->download($res['download_url'])->assertStatus(410);
        $this->get('/files/claude/'.str_repeat('a', 48))->assertNotFound();
    }

    public function test_finance_reports_need_finance_access(): void
    {
        $paralegal = $this->user('paralegal', 'pl@tools.test');
        $this->assertArrayHasKey('error', $this->tool($paralegal, 'generate_report', ['type' => 'payment-collection', 'format' => 'pdf']));
        $this->assertArrayNotHasKey('pending_payments', app(ToolRegistry::class)->for($paralegal));
        $types = array_column($this->tool($paralegal, 'list_reports')['reports'], 'type');
        $this->assertNotContains('payment-collection', $types);
        $this->assertContains('matter-status', $types);
    }

    public function test_sign_report_with_the_signatory_pin_only(): void
    {
        $res = $this->tool($this->analyst, 'generate_report', ['type' => 'matter-status', 'format' => 'pdf']);
        $bad = $this->tool($this->analyst, 'sign_report', ['export_id' => $res['export_id'], 'pin' => '1111']);
        $this->assertStringContainsString('not right', $bad['error']);

        $ok = $this->tool($this->analyst, 'sign_report', ['export_id' => $res['export_id'], 'pin' => '2580']);
        $this->assertTrue($ok['signed'], json_encode($ok));
        $this->assertSame('Mugilvannan L', $ok['signed_by']);
        $this->assertSame('AI Integration Engineer', $ok['designation']);

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/reports/verify/{$ok['verification_code']}")->assertOk()->assertJson(['valid' => true, 'signed_by' => 'Mugilvannan L']);
        $this->assertStringStartsWith('%PDF', (string) $this->download($ok['download_url'])->assertOk()->getContent());

        // The PIN is never in the audit log.
        $this->assertFalse(AuditLog::where('metadata', 'like', '%2580%')->exists());
        $this->assertFalse(AuditLog::where('metadata', 'like', '%1111%')->exists());

        // 5 wrong PINs lock signing.
        $other = $this->tool($this->analyst, 'generate_report', ['type' => 'matter-status', 'format' => 'csv']);
        for ($i = 0; $i < 5; $i++) {
            $this->tool($this->analyst, 'sign_report', ['export_id' => $other['export_id'], 'pin' => '0000']);
        }
        $this->assertStringContainsString('locked', $this->tool($this->analyst, 'sign_report', ['export_id' => $other['export_id'], 'pin' => '2580'])['error']);

        // Someone else's export is not visible.
        $stranger = $this->user('associate', 'st@tools.test');
        $this->assertArrayHasKey('error', $this->tool($stranger, 'sign_report', ['export_id' => $other['export_id'], 'pin' => '2580']));
    }

    public function test_finance_tools_are_scoped(): void
    {
        $this->invoice('971M001INPRV', 1000, now()->subDays(10)->toDateString());
        $this->invoice('971M002INPRV', 5000, now()->subDays(40)->toDateString());

        $mine = $this->tool($this->analyst, 'pending_payments');
        $this->assertSame(1, $mine['pending_count']);
        $this->assertSame('971M001INPRV-INV', $mine['invoices'][0]['invoice']);
        $this->assertSame(10, $mine['invoices'][0]['days_overdue']);

        $all = $this->tool($this->admin, 'pending_payments', ['overdue_only' => true]);
        $this->assertSame(2, $all['pending_count']);
        $this->assertSame('971M002INPRV-INV', $all['invoices'][0]['invoice']);      // most overdue first
        $this->assertSame(6000.0, (float) $all['totals_by_currency']['INR']);

        $statement = $this->tool($this->analyst, 'client_statement', ['client_code' => '971M']);
        $this->assertCount(1, $statement['invoices']);
        foreach (['collections', 'unbilled_and_quoted', 'revenue_forecast', 'revenue_breakdown', 'period_comparison'] as $tool) {
            $this->assertArrayNotHasKey('error', $this->tool($this->admin, $tool), $tool);
        }
    }

    public function test_case_team_and_hr_tools(): void
    {
        $case = Project::where('docket_number', '971M001INPRV')->first();
        $this->assertArrayNotHasKey('error', $this->tool($this->analyst, 'case_timeline', ['case_id' => $case->id]));
        $other = Project::where('docket_number', '971M002INPRV')->first();
        $this->assertArrayHasKey('error', $this->tool($this->analyst, 'case_timeline', ['case_id' => $other->id]));
        $this->assertArrayHasKey('error', $this->tool($this->analyst, 'case_discussions', ['case_id' => $other->id]));

        $this->assertSame(1, $this->tool($this->analyst, 'portfolio_summary')['totals']['total']);
        $this->assertSame(2, $this->tool($this->admin, 'portfolio_summary')['totals']['total']);
        $this->assertSame(1, $this->tool($this->analyst, 'dashboard_kpis')['open_cases']);
        $this->assertCount(1, $this->tool($this->analyst, 'my_day', ['days' => 7])['deadlines_next_7_days_on_my_cases']);

        $staff = $this->tool($this->analyst, 'staff_directory', ['query' => 'Mugil'])['staff'];
        $this->assertSame('Mugilvannan L', $staff[0]['name']);
        $this->assertArrayNotHasKey('phone', $staff[0]);
        $this->assertArrayNotHasKey('salary', $staff[0]);
        $this->assertArrayHasKey('documents', $this->tool($this->analyst, 'search_documents'));
        $this->assertArrayHasKey('rows', $this->tool($this->analyst, 'search_tracker'));

        // My HR: own record only (the analyst has none; the admin is the signatory employee).
        $this->assertArrayHasKey('error', $this->tool($this->analyst, 'my_hr'));
        $this->assertSame('Mugilvannan L', $this->tool($this->admin, 'my_hr')['employee']);

        $cal = $this->tool($this->analyst, 'deadline_calendar', ['days' => 30]);
        $ics = (string) $this->download($cal['download_url'])->assertOk()->getContent();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('971M001INPRV', $ics);
    }

    public function test_mcp_prompts_and_annotations(): void
    {
        $token = $this->analyst->createToken('Claude connector', ['mcp:read'])->plainTextToken;
        $rpc = function (string $method, array $params = []) use ($token) {
            $this->app['auth']->forgetGuards();

            return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], ['Authorization' => 'Bearer '.$token]);
        };
        $rpc('initialize')->assertJsonPath('result.capabilities.prompts.listChanged', false);
        $tools = collect($rpc('tools/list')->json('result.tools'))->keyBy('name');
        $this->assertGreaterThanOrEqual(30, $tools->count());
        $this->assertFalse($tools['generate_report']['annotations']['readOnlyHint']);
        $this->assertTrue($tools['search_cases']['annotations']['readOnlyHint']);
        $this->assertTrue($tools->every(fn ($t) => $t['annotations']['destructiveHint'] === false));

        $names = collect($rpc('prompts/list')->json('result.prompts'))->pluck('name');
        $this->assertContains('weekly_deadline_briefing', $names);
        $this->assertContains('collections_follow_up', $names);          // the analyst has CM-scoped finance
        $text = $rpc('prompts/get', ['name' => 'client_review_pack', 'arguments' => ['client_code' => '971M']])->json('result.messages.0.content.text');
        $this->assertStringContainsString('971M', $text);

        // A file tool answers with a resource link.
        $content = $rpc('tools/call', ['name' => 'generate_report', 'arguments' => ['type' => 'matter-status', 'format' => 'csv']])->json('result.content');
        $this->assertSame('resource_link', $content[1]['type']);
        $this->assertStringContainsString('/files/claude/', $content[1]['uri']);

        $paralegal = $this->user('paralegal', 'pl2@tools.test');
        $ptoken = $paralegal->createToken('Claude connector', ['mcp:read'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $pnames = collect($this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'prompts/list'], ['Authorization' => 'Bearer '.$ptoken])->json('result.prompts'))->pluck('name');
        $this->assertNotContains('collections_follow_up', $pnames);
    }
}
