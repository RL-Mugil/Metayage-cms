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

    /**
     * What a client owes and can be reminded of, with the date its ageing counts from: unpaid
     * invoices (due date) and, under quote-first billing, unpaid quotes (quote date).
     *
     * @return Collection<int, array{item: PatentInvoiceIn, due_on: Carbon, amount: float}>
     */
    public function openItems(): Collection
    {
        $items = PatentInvoiceIn::where('type', 'invoice')->whereNotIn('status', ['Draft', 'Cancelled'])->whereNotNull('client_id')->whereNotNull('due_date')
            ->where('payment_status', '!=', 'Confirmed')->where('balance_due', '>', 0)->get()
            ->map(fn ($i) => ['item' => $i, 'due_on' => $i->due_date->copy(), 'amount' => round((float) $i->balance_due * ((float) ($i->exchange_rate ?? 1) ?: 1), 2)]);
        if (QuoteFirst::enabled()) {
            $exempt = QuoteFirst::settings()['exempt_client_ids'];
            foreach (PatentInvoiceIn::openQuotes()->whereNotNull('client_id')->whereNotNull('invoice_date')
                ->when($exempt !== [], fn ($q) => $q->whereNotIn('client_id', $exempt))->get() as $quote) {
                $state = QuoteFirst::paidState($quote);
                if ($state['due'] > 0.009 && ! $state['fully_paid']) {
                    $items->push(['item' => $quote, 'due_on' => $quote->invoice_date->copy(), 'amount' => $state['due']]);
                }
            }
        }

        return $items;
    }

    /** Sends interrupted (queued or sending for 15+ minutes) become "failed", so they can be checked and retried. */
    public static function sweepStuck(): int
    {
        return CollectionReminder::whereIn('status', ['queued', 'sending'])->where('updated_at', '<', now()->subMinutes(15))
            ->update(['status' => 'failed', 'error' => 'Interrupted while sending: check whether the client received it before resending.']);
    }

    /**
     * Draft reminders for today: one draft per client with everything due a reminder (the highest band
     * reached decides the band). An older unsent draft for the client is replaced, so an invoice is
     * never in two drafts. An item already reminded at its current band is not reminded again.
     */
    public function prepare(?Carbon $today = null): Collection
    {
        self::sweepStuck();
        $today = ($today ?? now('Asia/Kolkata'))->copy()->startOfDay();
        $cfg = self::settings();
        $paused = CollectionClientSetting::whereNotNull('paused_until')->where('paused_until', '>=', $today->toDateString())->pluck('client_id')->flip();
        $created = collect();

        foreach ($this->openItems()->groupBy(fn ($r) => $r['item']->client_id) as $clientId => $items) {
            if (isset($paused[$clientId])) {
                continue;
            }
            $sent = CollectionReminder::where('client_id', $clientId)->whereIn('status', ['sent', 'queued', 'sending'])->get(['band', 'invoice_ids']);
            $drafts = CollectionReminder::where('client_id', $clientId)->where('status', 'draft')->get();
            $remindedAt = fn ($reminders, int $band, int $id) => $reminders->contains(fn ($r) => (int) $r->band >= $band && in_array($id, $r->invoice_ids ?? [], true));
            $due = [];
            $new = false;
            foreach ($items as $row) {
                $inv = $row['item'];
                if ($inv->promised_on && $inv->promised_on->gte($today)) {
                    continue;   // the client promised a date that has not passed
                }
                $days = (int) $row['due_on']->startOfDay()->diffInDays($today, false);   // + overdue, − before due
                $band = collect($cfg['bands'])->filter(fn ($b) => $days >= $b)->last();
                if ($band === null || $remindedAt($sent, $band, $inv->id)) {
                    continue;
                }
                $due[] = $row + ['band' => $band];
                $new = $new || ! $remindedAt($drafts, $band, $inv->id);
            }
            if ($due === [] || ! $new) {
                continue;   // nothing new since the waiting draft
            }
            $drafts->each->update(['status' => 'skipped', 'error' => 'Superseded by a later reminder']);
            $created->push($this->draft(Client::find($clientId), (int) max(array_column($due, 'band')), collect($due), $cfg));
        }
        $this->notify($created, $cfg);

        return $created;
    }

    private function draft(Client $client, int $band, Collection $due, array $cfg): CollectionReminder
    {
        $quotes = $due->filter(fn ($r) => $r['item']->type === 'quote')->count();
        $invoices = $due->count() - $quotes;
        $what = collect([$invoices ? $invoices.' invoice'.($invoices === 1 ? '' : 's') : null, $quotes ? $quotes.' proforma'.($quotes === 1 ? '' : 's') : null])->filter()->implode(' and ');
        $subject = ($band < 0 ? 'Upcoming payment' : 'Payment reminder').': '.$what.' · '.($client->legal_name ?: $client->company_name);

        return CollectionReminder::create([
            'client_id' => $client->id, 'band' => $band, 'invoice_ids' => $due->pluck('item.id')->values()->all(), 'amount' => round((float) $due->sum('amount'), 2),
            'recipients' => $this->recipients($client), 'subject' => $subject,
            'body' => ($band < 0 ? "These fall due in the next few days.\n\n" : '').$cfg['intro']
                .($quotes ? "\n\nProforma invoices: the tax invoice is issued once payment is received." : ''),
            'status' => 'draft', 'escalated' => $band >= $cfg['escalate_after'],
        ]);
    }

    /** The items of a reminder still unpaid at send time (paid ones are left out). */
    public static function stillOpen(CollectionReminder $reminder): Collection
    {
        return PatentInvoiceIn::whereIn('id', $reminder->invoice_ids ?? [])->get()->filter(function (PatentInvoiceIn $i) {
            if ($i->type === 'quote') {
                return PatentInvoiceIn::openQuotes()->whereKey($i->id)->exists() && ! QuoteFirst::paidState($i)['fully_paid'];
            }

            return (float) $i->balance_due > 0.009 && $i->payment_status !== 'Confirmed';
        })->values();
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
        $created = (new \Illuminate\Database\Eloquent\Collection($created->all()))->load('client:id,client_code,legal_name,account_manager_id');
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
