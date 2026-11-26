<?php

namespace App\Jobs;

use App\Mail\ClientPaymentReminderMail;
use App\Models\AuditLog;
use App\Models\CollectionReminder;
use App\Models\User;
use App\Services\ClientStatement;
use App\Services\CollectionsPlanner;
use App\Services\Letterhead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Send one approved payment reminder (queued, so a bulk send never times out): items paid in the
 * meantime are left out, the client's statement is attached, and the result is recorded.
 */
class SendCollectionReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $reminderId, public int $senderId, public ?string $ip = null) {}

    public function handle(): void
    {
        $reminder = CollectionReminder::with('client')->find($this->reminderId);
        if (! $reminder || $reminder->status !== 'queued') {
            return;
        }
        $reminder->update(['status' => 'sending']);
        $items = CollectionsPlanner::stillOpen($reminder);
        if ($items->isEmpty() || empty($reminder->recipients)) {
            $reminder->update(['status' => 'skipped', 'error' => $items->isEmpty() ? 'Paid before sending.' : 'No recipient email.']);

            return;
        }
        $sender = User::find($this->senderId);
        $cfg = CollectionsPlanner::settings();
        $statement = app(ClientStatement::class)->render($reminder->client, $sender ?? User::where('role', 'super_admin')->firstOrFail(), 'pdf', 'client');
        Mail::to(collect($reminder->recipients)->pluck('email')->all())
            ->send(new ClientPaymentReminderMail($reminder, $items, $statement, app(Letterhead::class)->get(), $cfg['reply_to'], $cfg['closing']));
        $reminder->update(['status' => 'sent', 'sent_by_id' => $this->senderId, 'sent_at' => now(), 'error' => null, 'invoice_ids' => $items->pluck('id')->values()->all()]);
        AuditLog::create(['user_id' => $this->senderId, 'action' => 'payment_reminder_sent', 'subject_type' => 'Client', 'subject_id' => $reminder->client_id,
            'metadata' => ['reminder_id' => $reminder->id, 'band' => $reminder->band, 'items' => $items->map(fn ($i) => $i->invoice_uin ?: $i->zoho_number)->all(),
                'to' => collect($reminder->recipients)->pluck('email')->all()], 'ip_address' => $this->ip]);
    }

    public function failed(\Throwable $e): void
    {
        CollectionReminder::whereKey($this->reminderId)->whereIn('status', ['queued', 'sending'])
            ->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
    }
}
