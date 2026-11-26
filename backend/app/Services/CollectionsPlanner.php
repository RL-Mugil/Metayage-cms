<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\CollectionClientSetting;
use App\Models\CollectionReminder;
use App\Models\PatentInvoiceIn;
use App\Models\User;
use App\Support\Notifier;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Payment reminders, drafted daily by ageing band (days relative to the due date) and sent only
 * once the client's Client Manager or Accounts approves. An invoice with a future promise-to-pay
 * date is left out; a broken promise brings it back. Paused clients are skipped. Past the
 * escalation day, Accounts is told as well as the Client Manager.
 */
class CollectionsPlanner
{
    public const SETTINGS_KEY = 'collections_settings';

    /** @return array{bands: array<int, int>, escalate_after: int, reply_to: ?string, intro: string, closing: string} */
    public static function settings(): array
    {
        $saved = json_decode((string) DB::table('system_settings')->where('key', self::SETTINGS_KEY)->value('value'), true) ?: [];
        $bands = array_values(array_unique(array_map('intval', $saved['bands'] ?? [-3, 7, 30, 60])));
        sort($bands);

        return [
            'bands' => $bands,
            'escalate_after' => (int) ($saved['escalate_after'] ?? 60),
            'reply_to' => $saved['reply_to'] ?? null,
            'intro' => $saved['intro'] ?? 'This is a friendly reminder about the invoices below. If you have already paid, thank you, and please ignore this note or reply with the payment reference.',
            'closing' => $saved['closing'] ?? 'Your statement of account is attached. Please reply to this email if you have any questions.',
        ];
    }

    /** Unpaid invoices with a due date: the client's open items. */
    private function openInvoices(): Collection
    {
        return PatentInvoiceIn::where('type', 'invoice')->whereNotIn('status', ['Draft', 'Cancelled'])->whereNotNull('client_id')->whereNotNull('due_date')
            ->where('payment_status', '!=', 'Confirmed')->where('balance_due', '>', 0)->get();
    }

    /** Draft reminders for today. Returns the drafts created. */
    public function prepare(?Carbon $today = null): Collection
    {
        $today = ($today ?? now('Asia/Kolkata'))->copy()->startOfDay();
        $cfg = self::settings();
        $paused = CollectionClientSetting::whereNotNull('paused_until')->where('paused_until', '>=', $today->toDateString())->pluck('client_id')->flip();
        $created = collect();

        foreach ($this->openInvoices()->groupBy('client_id') as $clientId => $invoices) {
            if (isset($paused[$clientId])) {
                continue;
            }
            $existing = CollectionReminder::where('client_id', $clientId)->whereIn('status', ['draft', 'sent'])->get(['band', 'invoice_ids']);
            $already = fn (int $band, int $id) => $existing->contains(fn ($r) => (int) $r->band === $band && in_array($id, $r->invoice_ids ?? [], true));
            $byBand = [];
            foreach ($invoices as $inv) {
                if ($inv->promised_on && $inv->promised_on->gte($today)) {
                    continue;   // the client promised a date that has not passed
                }
                $days = (int) $inv->due_date->copy()->startOfDay()->diffInDays($today, false);   // + overdue, − before due
                $band = collect($cfg['bands'])->filter(fn ($b) => $days >= $b)->last();
                if ($band === null || $already($band, $inv->id)) {
                    continue;
                }
                $byBand[$band][] = $inv;
            }
            foreach ($byBand as $band => $items) {
                $created->push($this->draft(Client::find($clientId), (int) $band, collect($items), $cfg));
            }
        }
        $this->notify($created, $cfg);

        return $created;
    }

    private function draft(Client $client, int $band, Collection $invoices, array $cfg): CollectionReminder
    {
        $amount = round((float) $invoices->sum(fn ($i) => (float) $i->balance_due * ((float) ($i->exchange_rate ?? 1) ?: 1)), 2);
        $subject = ($band < 0 ? 'Upcoming payment' : 'Payment reminder').': '.$invoices->count().' invoice'.($invoices->count() === 1 ? '' : 's').' · '.($client->legal_name ?: $client->company_name);

        return CollectionReminder::create([
            'client_id' => $client->id, 'band' => $band, 'invoice_ids' => $invoices->pluck('id')->values()->all(), 'amount' => $amount,
            'recipients' => $this->recipients($client), 'subject' => $subject,
            'body' => $band < 0 ? "These invoices fall due in the next few days.\n\n".$cfg['intro'] : $cfg['intro'],
            'status' => 'draft', 'escalated' => $band >= $cfg['escalate_after'],
        ]);
    }

    /** Billing contacts (role Billing / designation Accounts Person), else Primary, else anyone with an email; or the client's saved override. */
    public function recipients(Client $client): array
    {
        $override = CollectionClientSetting::where('client_id', $client->id)->value('recipients');
        if (is_array($override) && $override !== []) {
            return array_values($override);
        }
        $contacts = ClientContact::where('client_id', $client->id)->whereNotNull('email')->where('email', '!=', '')->get(['name', 'email', 'role_type', 'designation']);
        $billing = $contacts->filter(fn ($c) => stripos((string) $c->role_type, 'billing') !== false || stripos((string) $c->designation, 'account') !== false);
        $chosen = $billing->isNotEmpty() ? $billing : $contacts->filter(fn ($c) => stripos((string) $c->role_type, 'primary') !== false);
        $chosen = $chosen->isNotEmpty() ? $chosen : $contacts;

        return $chosen->map(fn ($c) => ['name' => $c->name, 'email' => strtolower(trim($c->email))])->unique('email')->values()->all();
    }

    /** Client Managers hear about their new drafts; escalated ones go to Accounts too. */
    private function notify(Collection $created, array $cfg): void
    {
        if ($created->isEmpty()) {
            return;
        }
        $created->load('client:id,client_code,legal_name,account_manager_id');
        foreach ($created->groupBy(fn ($r) => (int) $r->client?->account_manager_id) as $managerId => $drafts) {
            if ($managerId) {
                Notifier::push([$managerId], 'collections', 'Payment reminders to review',
                    $drafts->count().' reminder(s) drafted for your clients: '.$drafts->map(fn ($r) => $r->client?->client_code)->filter()->unique()->implode(', '), '/pending-payments?tab=reminders');
            }
        }
        $escalated = $created->where('escalated', true);
        if ($escalated->isNotEmpty()) {
            $accounts = User::where('status', 'Active')->get()->filter(fn ($u) => InvoiceSettlement::canPay($u))->pluck('id')->all();
            Notifier::push($accounts, 'collections_escalation', "Invoices over {$cfg['escalate_after']} days overdue",
                $escalated->map(fn ($r) => $r->client?->client_code.' ₹'.number_format((float) $r->amount))->implode('; '), '/pending-payments?tab=reminders');
        }
    }

    /** May this user send / edit a client's reminder: the client's Client Manager, or Accounts. */
    public static function canSend(User $user, Client $client): bool
    {
        return InvoiceSettlement::canPay($user) || (int) $client->account_manager_id === (int) $user->id;
    }
}
