<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Integration;
use App\Models\Project;
use App\Models\ZohoInvoice;
use App\Models\ZohoSnapshot;
use App\Services\ZohoApiBudget;
use App\Services\ZohoBooksService;
use App\Services\ZohoDeletionChecks;
use App\Services\ZohoMirrorSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The nightly Zoho sync spends its allowance on invoices and quotes of portal projects first. */
class ZohoPortalPriorityTest extends TestCase
{
    use RefreshDatabase;

    private const SCOPE = 'in:123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 8)->setTime(4, 0));
        Storage::fake('local');
        Integration::create(['slug' => 'zoho', 'name' => 'Zoho Books', 'description' => 'Accounting', 'category' => 'Finance',
            'initials' => 'ZB', 'color' => 'red', 'connected' => true,
            'config' => ['client_id' => 'test', 'client_secret' => encrypt('secret'), 'refresh_token' => encrypt('refresh'), 'organization_id' => '123', 'region' => 'in']]);
        Cache::put('zoho_access_token:in:123', 'fake-token', 3300);
    }

    private function project(bool $portal = true, bool $deleted = false): Project
    {
        static $n = 0;
        $n++;
        $client = Client::create(['client_code' => 'C'.$n, 'client_type' => 'organization', 'legal_name' => 'Client '.$n, 'company_name' => 'Client '.$n,
            'nationality' => 'India', 'status' => 'Active', 'portal_enabled' => $portal]);
        $project = Project::create(['project_code' => "P{$n}", 'docket_number' => "P{$n}INPAT", 'client_id' => $client->id, 'project_type' => 'Patent',
            'project_name' => "Case {$n}", 'patent_office_code' => 'IN', 'service_code' => 'PAT', 'invention_number' => '001']);
        if ($deleted) {
            $project->delete();
        }

        return $project;
    }

    /** One mirrored Zoho record with its snapshot. $age = how many days ago its detail was last read (null = never). */
    private function record(string $resource, string $id, ?Project $project, ?int $age = null): void
    {
        ZohoSnapshot::create(['scope' => self::SCOPE, 'resource' => $resource, 'remote_id' => $id, 'summary' => '{}', 'fingerprint' => hash('sha256', $id),
            'detail_synced_at' => $age === null ? null : now()->subDays($age)]);
        if (in_array($resource, ['invoices', 'estimates'], true)) {
            ZohoInvoice::create(['scope' => self::SCOPE, 'zoho_id' => $id, 'zoho_type' => $resource === 'invoices' ? 'invoice' : 'quote', 'project_id' => $project?->id,
                'number' => 'N'.$id, 'total' => 100]);
        }
    }

    /** Fake Zoho that records the ids requested, in order (details only; PDFs are served but not listed). */
    private function fakeRemote(array &$requested): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use (&$requested) {
            if (str_contains($request->url(), '/oauth/')) {
                return Http::response(['access_token' => 'fake-token']);
            }
            $parts = explode('/', trim(parse_url($request->url(), PHP_URL_PATH), '/'));
            [$resource, $id] = [$parts[2], $parts[3] ?? null];
            if (($request['accept'] ?? null) === 'pdf') {
                return Http::response('%PDF-1.4 fixture', 200, ['Content-Type' => 'application/pdf']);
            }
            $requested[] = $resource.':'.$id;
            [$idKey, $key] = ZohoBooksService::RESOURCES[$resource];

            return Http::response([$key => [$idKey => $id, 'status' => 'paid', 'total' => 100, 'balance' => 0]]);
        });
    }

    /** Call a private sync step as run() would, with the 60-minute run timer started. */
    private function step(string $method, mixed ...$args): void
    {
        $sync = app(ZohoMirrorSync::class);
        (new \ReflectionProperty($sync, 'startedAt'))->setValue($sync, microtime(true));
        (new \ReflectionProperty($sync, 'zoho'))->getValue($sync)->background = true;   // the nightly pool, as run() sets it
        (new \ReflectionMethod($sync, $method))->invoke($sync, ...$args);
    }

    private function hydrateOrder(): array
    {
        $requested = [];
        $this->fakeRemote($requested);
        $this->step('hydratePending', false);

        return array_values(array_filter($requested, fn ($r) => ! str_ends_with($r, ':')));
    }

    public function test_project_linked_invoices_and_quotes_are_downloaded_before_older_and_other_records(): void
    {
        $project = $this->project();
        // Older, unrelated records first in age order; the portal-project ones are newer but must still go first.
        $this->record('contacts', 'C-old', null, 90);
        $this->record('customerpayments', 'PAY-old', null, 80);
        $this->record('invoices', 'INV-unlinked', null, 70);
        $this->record('invoices', 'INV-portal', $project, 3);
        $this->record('estimates', 'QUO-portal', $project, 2);

        $order = $this->hydrateOrder();

        $this->assertEqualsCanonicalizing(['invoices:INV-portal', 'estimates:QUO-portal'], array_slice($order, 0, 2));
        $this->assertEqualsCanonicalizing(['contacts:C-old', 'customerpayments:PAY-old', 'invoices:INV-unlinked'], array_slice($order, 2, 3));
        // ... and within the rest, the oldest still goes first
        $this->assertSame(['contacts:C-old', 'customerpayments:PAY-old', 'invoices:INV-unlinked'], array_slice($order, 2, 3));
    }

    public function test_a_client_with_portal_access_switched_off_still_counts_but_deleted_projects_and_unlinked_records_do_not(): void
    {
        $off = $this->project(portal: false);
        $gone = $this->project(deleted: true);
        $this->record('invoices', 'INV-off', $off, 5);
        $this->record('invoices', 'INV-gone', $gone, 9);
        $this->record('invoices', 'INV-none', null, 20);

        $order = $this->hydrateOrder();

        $this->assertSame('invoices:INV-off', $order[0], 'a project in the portal counts even when the client portal access is off');
        $this->assertSame(['invoices:INV-none', 'invoices:INV-gone'], array_slice($order, 1), 'a deleted project or no project is not prioritised (oldest first)');
    }

    public function test_the_priority_changes_the_order_only_never_the_budget(): void
    {
        $project = $this->project();
        $this->record('contacts', 'C-1', null, 60);
        $this->record('invoices', 'INV-portal', $project, 1);
        DB::table('zoho_api_budgets')->updateOrInsert(['scope' => self::SCOPE], [
            'budget_date' => now('Asia/Kolkata')->toDateString(), 'calls' => ZohoApiBudget::NIGHTLY_LIMIT - 1, 'automatic_calls' => ZohoApiBudget::NIGHTLY_LIMIT - 1,
        ]);
        $requested = [];
        $this->fakeRemote($requested);

        try {
            $this->step('hydratePending', false);
            $this->fail('the nightly pool must still run out');
        } catch (\App\Exceptions\ZohoBudgetExhausted) {
            // expected: one call was left and the priority record took it
        }

        $this->assertSame(['invoices:INV-portal'], $requested);
        $this->assertSame(ZohoApiBudget::NIGHTLY_LIMIT, (int) DB::table('zoho_api_budgets')->where('scope', self::SCOPE)->value('automatic_calls'));
    }

    public function test_the_daily_check_reads_portal_project_records_first_and_reports_the_counts(): void
    {
        $project = $this->project();
        $this->record('invoices', 'INV-old', null, 40);
        $this->record('invoices', 'INV-portal', $project, 2);

        $requested = [];
        $this->fakeRemote($requested);
        $this->step('checkDeletions', 1, true);   // a priority batch of one

        $this->assertSame(['invoices:INV-portal'], $requested);

        $status = app(ZohoDeletionChecks::class)->status(self::SCOPE);
        $this->assertSame(1, $status['portal_projects']['priority_total']);
        $this->assertSame(0, $status['portal_projects']['priority_due'], 'the portal-project record was just read');
        $this->assertSame(1, $status['due'], 'the older unlinked record is still waiting');
    }
}
