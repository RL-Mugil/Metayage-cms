<?php

namespace App\Http\Controllers;

use App\Mail\ClientPaymentReminderMail;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\CollectionClientSetting;
use App\Models\CollectionReminder;
use App\Models\PatentInvoiceIn;
use App\Services\ClientStatement;
use App\Services\CollectionsPlanner;
use App\Services\InvoiceSettlement;
use App\Services\Letterhead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Pending Payments → Reminders: payment reminders drafted by the portal, reviewed and sent by the
 * client's Client Manager or Accounts (nothing reaches a client unseen). Also per-client pause and
 * recipients, and the reminder settings (Accounts).
 */
class CollectionReminderController extends Controller
{
    private function visible(Request $request)
    {
        $user = $request->user();
        abort_if($user->isClientRole(), 403);
        $query = CollectionReminder::query()->with('client:id,client_code,legal_name,company_name,account_manager_id', 'sentBy:id,name');
        if (! InvoiceSettlement::canPay($user)) {
            $query->whereHas('client', fn ($c) => $c->where('account_manager_id', $user->id));
        }

        return $query;
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => 'nullable|in:draft,sent,skipped,failed'])['status'] ?? 'draft';
        $rows = $this->visible($request)->where('status', $status)->orderByDesc('escalated')->orderByDesc('band')->latest('id')->limit(300)->get();
        $invoices = PatentInvoiceIn::whereIn('id', $rows->pluck('invoice_ids')->flatten()->unique())->get()->keyBy('id');
        $paused = CollectionClientSetting::whereIn('client_id', $rows->pluck('client_id'))->get()->keyBy('client_id');

        return response()->json([
            'data' => $rows->map(fn (CollectionReminder $r) => [
                'id' => $r->id, 'band' => $r->band, 'status' => $r->status, 'escalated' => $r->escalated, 'amount' => (float) $r->amount,
                'subject' => $r->subject, 'body' => $r->body, 'recipients' => $r->recipients, 'error' => $r->error,
                'sent_at' => $r->sent_at?->toIso8601String(), 'sent_by' => $r->sentBy?->name, 'created_at' => $r->created_at?->toIso8601String(),
                'client' => ['id' => $r->client_id, 'code' => $r->client?->client_code, 'name' => $r->client?->legal_name ?: $r->client?->company_name,
                    'paused_until' => $paused->get($r->client_id)?->paused_until?->toDateString()],
                'invoices' => collect($r->invoice_ids)->map(fn ($id) => $invoices->get($id))->filter()->map(fn (PatentInvoiceIn $i) => [
                    'id' => $i->id, 'uin' => $i->invoice_uin ?: $i->zoho_number, 'due_date' => $i->due_date?->toDateString(), 'currency' => $i->currency ?: 'INR',
                    'balance' => (float) $i->balance_due, 'paid' => (float) $i->balance_due <= 0.009 || $i->payment_status === 'Confirmed',
                    'promised_on' => $i->promised_on?->toDateString()])->values(),
                'can_send' => $r->client ? CollectionsPlanner::canSend($request->user(), $r->client) : false,
            ]),
            'counts' => (clone $this->visible($request))->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status'),
            'settings' => CollectionsPlanner::settings(),
            'can' => ['settings' => InvoiceSettlement::canPay($request->user())],
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $reminder = $this->visible($request)->findOrFail($id);
        abort_unless(CollectionsPlanner::canSend($request->user(), $reminder->client), 403);
        abort_unless($reminder->status === 'draft', 422, 'Only a draft can be edited.');
        $data = $request->validate(['subject' => 'required|string|max:255', 'body' => 'required|string|max:4000',
            'recipients' => 'required|array|min:1|max:10', 'recipients.*.email' => 'required|email:rfc', 'recipients.*.name' => 'nullable|string|max:120']);
        $reminder->update($data);

        return response()->json(['ok' => true]);
    }

    /** Send one or more drafts. Paid invoices are left out; a draft with nothing left unpaid is skipped. */
    public function send(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'integer'])['ids'];
        $cfg = CollectionsPlanner::settings();
        $head = app(Letterhead::class)->get();
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($this->visible($request)->whereIn('id', $ids)->get() as $reminder) {
            if (! CollectionsPlanner::canSend($request->user(), $reminder->client)) {
                continue;
            }
            // Claim the draft first, so a double click or a second sender cannot send it twice.
            $claimed = DB::transaction(fn () => CollectionReminder::whereKey($reminder->id)->where('status', 'draft')->lockForUpdate()->update(['status' => 'sending']));
            if (! $claimed) {
                continue;
            }
            $invoices = PatentInvoiceIn::whereIn('id', $reminder->invoice_ids)->where('balance_due', '>', 0)->where('payment_status', '!=', 'Confirmed')->get();
            if ($invoices->isEmpty() || empty($reminder->recipients)) {
                $reminder->update(['status' => 'skipped', 'error' => $invoices->isEmpty() ? 'Paid before sending.' : 'No recipient email.']);
                $result['skipped']++;

                continue;
            }
            try {
                $statement = app(ClientStatement::class)->render($reminder->client, $request->user(), 'pdf', 'client');
                Mail::to(collect($reminder->recipients)->pluck('email')->all())
                    ->send(new ClientPaymentReminderMail($reminder, $invoices, $statement, $head, $cfg['reply_to'], $cfg['closing']));
                $reminder->update(['status' => 'sent', 'sent_by_id' => $request->user()->id, 'sent_at' => now(), 'error' => null,
                    'invoice_ids' => $invoices->pluck('id')->values()->all()]);
                AuditLog::create(['user_id' => $request->user()->id, 'action' => 'payment_reminder_sent', 'subject_type' => 'Client', 'subject_id' => $reminder->client_id,
                    'metadata' => ['reminder_id' => $reminder->id, 'band' => $reminder->band, 'invoices' => $invoices->pluck('invoice_uin')->all(),
                        'to' => collect($reminder->recipients)->pluck('email')->all()], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
                $result['sent']++;
            } catch (\Throwable $e) {
                report($e);
                $reminder->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
                $result['failed']++;
            }
        }

        return response()->json($result);
    }

    public function skip(Request $request, int $id): JsonResponse
    {
        $reminder = $this->visible($request)->findOrFail($id);
        abort_unless(CollectionsPlanner::canSend($request->user(), $reminder->client), 403);
        abort_unless(in_array($reminder->status, ['draft', 'failed'], true), 422, 'Only a draft can be skipped.');
        $reminder->update(['status' => 'skipped', 'error' => 'Skipped by '.$request->user()->name]);

        return response()->json(['ok' => true]);
    }

    /** Pause reminders for a client until a date, and/or set who receives them. */
    public function clientSettings(Request $request, int $clientId): JsonResponse
    {
        $client = Client::findOrFail($clientId);
        abort_unless(CollectionsPlanner::canSend($request->user(), $client), 403);
        $data = $request->validate(['paused_until' => 'nullable|date', 'recipients' => 'nullable|array|max:10', 'recipients.*.email' => 'required|email:rfc',
            'recipients.*.name' => 'nullable|string|max:120', 'note' => 'nullable|string|max:255']);
        CollectionClientSetting::updateOrCreate(['client_id' => $client->id], $data);
        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'collection_client_settings', 'subject_type' => 'Client', 'subject_id' => $client->id,
            'metadata' => ['paused_until' => $data['paused_until'] ?? null, 'recipients' => count($data['recipients'] ?? [])], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        return response()->json(['ok' => true]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        abort_unless(InvoiceSettlement::canPay($request->user()), 403);
        $data = $request->validate(['bands' => 'required|array|min:1|max:8', 'bands.*' => 'integer|min:-60|max:365', 'escalate_after' => 'required|integer|min:1|max:365',
            'reply_to' => 'nullable|email:rfc', 'intro' => 'required|string|max:1000', 'closing' => 'required|string|max:1000']);
        DB::table('system_settings')->updateOrInsert(['key' => CollectionsPlanner::SETTINGS_KEY], ['value' => json_encode($data), 'updated_at' => now()]);
        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'collections_settings_updated', 'subject_type' => 'Finance', 'subject_id' => 0,
            'metadata' => ['bands' => $data['bands'], 'escalate_after' => $data['escalate_after']], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        return response()->json(CollectionsPlanner::settings());
    }
}
