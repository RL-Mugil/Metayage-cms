<?php

namespace Tests\Feature;

use App\Models\{Client, ClientLedger, Integration, RegisterPayment, ZohoInvoice, ZohoSnapshot};
use App\Services\ZohoMirrorSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Http};
use Tests\TestCase;

/**
 * The payment check: payments Zoho has but the portal never downloaded are added (an advance
 * applied to no invoice), and a payment deleted in Zoho (a stale copy settling an invoice twice)
 * is removed, so the client ledger matches Zoho again.
 */
class ZohoPaymentReconcileTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Integration::create(['slug' => 'zoho', 'name' => 'Zoho Books', 'description' => 'Accounting', 'category' => 'Finance',
            'initials' => 'ZB', 'color' => 'red', 'connected' => true,
            'config' => ['client_id' => 'test', 'client_secret' => encrypt('secret'), 'refresh_token' => encrypt('refresh'), 'organization_id' => '123', 'region' => 'in']]);
        Cache::put('zoho_access_token:in:123', 'fake-token', 86400);
        $this->client = Client::create(['client_code' => '940M', 'client_type' => 'organization', 'legal_name' => 'Agnikul', 'company_name' => 'Agnikul',
            'nationality' => 'India', 'status' => 'Active', 'zoho_contact_id' => '900']);
        ZohoInvoice::create(['scope' => 'in:123', 'zoho_id' => '1', 'zoho_type' => 'invoice', 'number' => '940M001USNOA180', 'status' => 'paid',
            'total' => 100000, 'balance' => 0, 'currency' => 'INR', 'txn_date' => '2026-06-01', 'zoho_contact_id' => '900']);
    }

    private function payment(string $id, string $number, float $amount, float $unused, array $invoices): array
    {
        return ['payment_id' => $id, 'payment_number' => $number, 'date' => '2026-07-01', 'amount' => $amount, 'unused_amount' => $unused,
            'exchange_rate' => 1, 'currency_code' => 'INR', 'customer_id' => '900', 'invoices' => $invoices];
    }

    private function saved(array $payment): void
    {
        $s = ZohoSnapshot::create(['scope' => 'in:123', 'resource' => 'customerpayments', 'remote_id' => $payment['payment_id'], 'summary' => '{}',
            'detail' => json_encode($payment), 'fingerprint' => $payment['payment_id']]);
        DB::table('zoho_snapshots')->where('id', $s->id)->update(['created_at' => now()->subDay()]);
    }

    public function test_missing_payments_are_added_and_deleted_ones_removed(): void
    {
        $applied = [['invoice_id' => '1', 'amount_applied' => 100000, 'tax_amount_withheld' => 0]];
        $this->saved($this->payment('101', 'P195', 100000, 0, $applied));   // deleted in Zoho, still in the portal
        $this->saved($this->payment('102', 'P487', 100000, 0, $applied));
        $this->assertSame(2, RegisterPayment::where('kind', 'cash')->count());   // the invoice looks paid twice

        $advance = $this->payment('103', 'P600', 5000, 5000, []);
        $kept = $this->payment('102', 'P487', 100000, 0, $applied);
        Http::fake(function ($request) use ($advance, $kept) {
            $url = $request->url();
            if (str_contains($url, '/customerpayments/102?')) {   // re-read: it settles an invoice Zoho shows paid once
                return Http::response(['payment' => $kept]);
            }
            if (str_contains($url, '/customerpayments/101?')) {
                return Http::response(['code' => 1002, 'message' => 'Resource does not exist.'], 404);
            }
            if (str_contains($url, '/customerpayments/103?')) {
                return Http::response(['payment' => $advance]);
            }
            if (str_contains($url, '/customerpayments?')) {
                return Http::response(['customerpayments' => [
                    ['payment_id' => '102', 'payment_number' => 'P487', 'date' => '2026-07-01', 'amount' => 100000, 'unused_amount' => 0],
                    ['payment_id' => '103', 'payment_number' => 'P600', 'date' => '2026-07-01', 'amount' => 5000, 'unused_amount' => 5000],
                ], 'page_context' => ['has_more_page' => false]]);
            }
            if (str_contains($url, '/creditnotes?')) {
                return Http::response(['creditnotes' => [], 'page_context' => ['has_more_page' => false]]);
            }

            return Http::response([], 500);
        });

        $result = app(ZohoMirrorSync::class)->reconcilePayments();

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(1, $result['added']);
        $this->assertSame(1, $result['refreshed']);
        $this->assertSame(1, $result['removed']);
        $this->assertSoftDeleted('zoho_snapshots', ['resource' => 'customerpayments', 'remote_id' => '101']);
        $this->assertSame(['P487'], RegisterPayment::where('kind', 'cash')->pluck('payment_number')->all());

        // Invoice 1,00,000 paid once; the 5,000 advance is credit with us.
        $last = app(\App\Services\ClientStatement::class)->lines($this->client->id)->last();
        $this->assertSame('-5000.00', $last->balance);
        $this->assertTrue(ClientLedger::where('client_id', $this->client->id)->where('document_type', 'Balance credit')->where('credit', 5000)->exists());
    }

    public function test_an_implausible_number_of_missing_payments_removes_nothing(): void
    {
        foreach (range(1, ZohoMirrorSync::PAYMENT_RECONCILE_MAX_REMOVALS + 1) as $n) {
            $this->saved($this->payment((string) (500 + $n), "P{$n}", 10, 0, []));
        }
        Http::fake(fn ($request) => str_contains($request->url(), '/customerpayments?')
            ? Http::response(['customerpayments' => [], 'page_context' => ['has_more_page' => false]])
            : Http::response(['creditnotes' => [], 'page_context' => ['has_more_page' => false]]));

        $result = app(ZohoMirrorSync::class)->reconcilePayments();

        $this->assertSame(0, $result['removed']);
        $this->assertSame(ZohoMirrorSync::PAYMENT_RECONCILE_MAX_REMOVALS + 1, ZohoSnapshot::where('resource', 'customerpayments')->count());
    }
}
