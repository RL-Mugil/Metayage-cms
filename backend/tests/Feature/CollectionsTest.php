<?php

namespace Tests\Feature;

use App\Mail\ClientPaymentReminderMail;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\CollectionClientSetting;
use App\Models\CollectionReminder;
use App\Models\InvoiceFollowUp;
use App\Models\PatentInvoiceIn;
use App\Models\User;
use App\Services\CollectionsPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Payment reminders: drafted by band, promise / pause respected, sent only by the CM or Accounts, with the statement. */
class CollectionsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $cm;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->cm = $this->user('manager', 'cm@col.test');
        $this->finance = $this->user('finance', 'f@col.test');
        $this->client = Client::create(['client_code' => '601M', 'client_type' => 'organization', 'legal_name' => 'Delta Devices', 'company_name' => 'Delta Devices',
            'nationality' => 'India', 'status' => 'Active', 'account_manager_id' => $this->cm->id]);
        ClientContact::create(['client_id' => $this->client->id, 'name' => 'Priya (Accounts)', 'email' => 'ap@delta.test', 'role_type' => 'Billing']);
        ClientContact::create(['client_id' => $this->client->id, 'name' => 'CTO', 'email' => 'cto@delta.test', 'role_type' => 'Technical']);
    }

    private function user(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $email, 'password' => bcrypt('password'), 'role' => $role, 'status' => 'Active']);
    }

    private function invoice(string $uin, int $daysOverdue, float $balance = 10000): PatentInvoiceIn
    {
        return PatentInvoiceIn::create(['type' => 'invoice', 'status' => 'Sent', 'source' => 'manual', 'client_id' => $this->client->id, 'invoice_uin' => $uin,
            'invoice_amount' => $balance, 'total_bcy' => $balance, 'balance_due' => $balance, 'exchange_rate' => 1, 'payment_status' => 'Pending', 'currency' => 'INR',
            'invoice_date' => now()->subDays($daysOverdue + 30)->toDateString(), 'due_date' => now()->subDays($daysOverdue)->toDateString()]);
    }

    public function test_drafts_follow_the_bands_and_respect_promises_and_pauses(): void
    {
        $late = $this->invoice('601M001/1', 10);
        $promised = $this->invoice('601M002/1', 12);
        $old = $this->invoice('601M003/1', 70);

        // A promise to pay next week keeps one invoice out.
        Sanctum::actingAs($this->finance);
        $this->postJson("/api/pending-payments/{$promised->id}/status-note", ['status_note' => 'Paying next week', 'promised_on' => now()->addDays(7)->toDateString()])->assertOk();
        $this->assertSame(1, InvoiceFollowUp::where('patent_invoice_in_id', $promised->id)->count());

        $drafts = app(CollectionsPlanner::class)->prepare();
        $this->assertCount(2, $drafts);   // band 7 (the 10-day invoice) and band 60 (the 70-day one)
        $band7 = $drafts->firstWhere('band', 7);
        $this->assertSame([$late->id], $band7->invoice_ids);
        $this->assertSame([['name' => 'Priya (Accounts)', 'email' => 'ap@delta.test']], $band7->recipients);   // the billing contact only
        $this->assertTrue($drafts->firstWhere('band', 60)->escalated);
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('ip_notifications')->where('user_id', $this->finance->id)->where('type', 'collections_escalation')->exists());
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('ip_notifications')->where('user_id', $this->cm->id)->where('type', 'collections')->exists());

        // Run again the same day: nothing new.
        $this->assertCount(0, app(CollectionsPlanner::class)->prepare());

        // A paused client gets nothing.
        CollectionReminder::query()->delete();
        CollectionClientSetting::create(['client_id' => $this->client->id, 'paused_until' => now()->addDays(3)->toDateString()]);
        $this->assertCount(0, app(CollectionsPlanner::class)->prepare());
        Mail::assertNothingSent();   // drafting never emails a client
    }

    public function test_only_the_client_manager_or_accounts_send_and_the_statement_is_attached(): void
    {
        $this->invoice('601M001/1', 10);
        $paid = $this->invoice('601M004/1', 40);
        $drafts = app(CollectionsPlanner::class)->prepare();
        $band7 = $drafts->firstWhere('band', 7);
        $band30 = $drafts->firstWhere('band', 30);

        // Another manager sees nothing and cannot send.
        Sanctum::actingAs($this->user('manager', 'other@col.test'));
        $this->assertSame([], $this->getJson('/api/collections/reminders')->assertOk()->json('data'));
        $this->postJson('/api/collections/reminders/send', ['ids' => [$band7->id]])->assertOk()->assertJsonPath('sent', 0);
        Mail::assertNothingSent();

        // The Client Manager sends; the invoice paid in the meantime is skipped.
        $paid->update(['balance_due' => 0, 'payment_status' => 'Confirmed']);
        Sanctum::actingAs($this->cm);
        $result = $this->postJson('/api/collections/reminders/send', ['ids' => [$band7->id, $band30->id]])->assertOk()->json();
        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['skipped']);
        Mail::assertSent(ClientPaymentReminderMail::class, fn ($m) => $m->hasTo('ap@delta.test') && count($m->attachments()) === 1
            && str_starts_with($m->statement['name'], 'Statement-601M'));
        $this->assertSame('sent', $band7->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment_reminder_sent', 'subject_id' => $this->client->id]);

        // Sending again does nothing (already sent).
        $this->postJson('/api/collections/reminders/send', ['ids' => [$band7->id]])->assertOk()->assertJsonPath('sent', 0);
        Mail::assertSentCount(1);
    }
}
