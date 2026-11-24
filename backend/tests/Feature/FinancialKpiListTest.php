<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PatentInvoiceIn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Financial Suite KPI cards open lists from the same register their numbers come from. */
class FinancialKpiListTest extends TestCase
{
    use RefreshDatabase;

    public function test_kpi_lists_match_the_kpi_numbers(): void
    {
        Sanctum::actingAs(User::create(['name' => 'Finance', 'email' => 'f@kpi.test', 'password' => bcrypt('password'), 'role' => 'finance', 'status' => 'Active']));
        $client = Client::create(['client_code' => '821M', 'client_type' => 'organization', 'legal_name' => 'Kappa Ltd', 'company_name' => 'Kappa Ltd', 'nationality' => 'India', 'status' => 'Active']);
        $make = fn ($uin, $amount, $balance, $due) => PatentInvoiceIn::create(['type' => 'invoice', 'status' => 'Sent', 'source' => 'zoho', 'client_id' => $client->id,
            'invoice_uin' => $uin, 'invoice_amount' => $amount, 'total_bcy' => $amount, 'balance_due' => $balance, 'exchange_rate' => 1,
            'invoice_date' => now()->subDays(40)->toDateString(), 'due_date' => $due, 'currency' => 'INR']);
        $make('821M001INPRV/1', 10000, 0, now()->subDays(10)->toDateString());       // paid
        $make('821M002INCPT/1', 8000, 3000, now()->subDays(5)->toDateString());      // part-paid, overdue
        $make('821M003INFER/1', 5000, 5000, now()->addDays(20)->toDateString());     // unpaid, not yet due

        $stats = $this->getJson('/api/financial/stats')->assertOk()->json();
        $this->assertEquals(23000, $stats['total_billed']);
        $this->assertSame(1, $stats['overdue_count']);

        $count = fn (string $kind) => $this->getJson("/api/financial/kpi-list?kind={$kind}")->assertOk()->json('total');
        $this->assertSame(3, $count('billed'));
        $this->assertSame(2, $count('received'));       // fully and part paid
        $this->assertSame(2, $count('outstanding'));
        $this->assertSame($stats['overdue_count'], $count('overdue'));
        $this->assertSame('821M003INFER/1', $this->getJson('/api/financial/kpi-list?kind=outstanding&search=infer')->json('data.0.invoice_code'));
        $this->getJson('/api/financial/kpi-list?kind=bogus')->assertStatus(422);
    }
}
