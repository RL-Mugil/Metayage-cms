<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ClientLedger;
use App\Models\FinanceLockFlag;
use App\Models\FinancePeriodLock;
use App\Models\PatentInvoiceIn;
use App\Models\Quotation;
use App\Models\QuoteSendApproval;
use App\Services\InvoiceSettlement;
use App\Services\LedgerCheck;
use App\Support\FinancePeriod;
use App\Support\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Financial Suite → Close & controls (Accounts): month close (lock / unlock), changes that
 * reached a locked month, quotes waiting for approval before Zoho, the nightly ledger-vs-Zoho
 * check and exchange gain/loss.
 */
class FinanceControlsController extends Controller
{
    private const UNLOCK_ROLES = ['super_admin', 'partner'];

    private function allow(Request $request): void
    {
        abort_unless(InvoiceSettlement::canPay($request->user()), 403, 'Close & controls is for Accounts.');
    }

    public function index(Request $request): JsonResponse
    {
        $this->allow($request);
        $locks = FinancePeriodLock::with('lockedBy:id,name')->get()->keyBy('month');
        $months = collect(range(0, 17))->map(fn ($i) => now()->startOfMonth()->subMonths($i)->format('Y-m'))
            ->merge($locks->keys())->unique()->sortDesc()->values()
            ->map(fn ($m) => ['month' => $m, 'label' => Carbon::createFromFormat('Y-m', $m)->format('M Y'), 'locked' => isset($locks[$m]),
                'locked_by' => $locks->get($m)?->lockedBy?->name, 'locked_at' => $locks->get($m)?->locked_at?->toIso8601String(),
                'open_flags' => FinanceLockFlag::where('month', $m)->whereNull('reviewed_at')->count()]);

        $approvals = QuoteSendApproval::with('requestedBy:id,name', 'decidedBy:id,name')
            ->where(fn ($q) => $q->where('status', 'pending')->orWhere('decided_at', '>=', now()->subDays(30)))
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")->latest('id')->limit(60)->get();
        // The quotes behind the approvals, loaded once (not one query per row).
        $quotes = ['in' => PatentInvoiceIn::with('client:id,client_code,legal_name,company_name')->whereIn('id', $approvals->where('quote_kind', 'in')->pluck('quote_id'))->get()->keyBy('id'),
            'standard' => Quotation::with('client:id,client_code,legal_name,company_name')->whereIn('id', $approvals->where('quote_kind', 'standard')->pluck('quote_id'))->get()->keyBy('id')];
        $approvals = $approvals->map(fn (QuoteSendApproval $a) => $this->approvalRow($a, $quotes[$a->quote_kind === 'in' ? 'in' : 'standard']->get($a->quote_id)));

        $fyStart = now()->month >= 4 ? now()->startOfYear()->month(4) : now()->subYear()->startOfYear()->month(4);
        $fx = ClientLedger::where('document_type', 'Exchange difference')->where('transaction_date', '>=', $fyStart->toDateString())
            ->with('client:id,client_code,legal_name')->orderByDesc('transaction_date')->get();

        return response()->json([
            'months' => $months,
            'flags' => FinanceLockFlag::with('client:id,client_code,legal_name')->whereNull('reviewed_at')->latest('id')->limit(200)->get()
                ->map(fn ($f) => ['id' => $f->id, 'month' => $f->month, 'what' => $f->what, 'reference' => $f->reference, 'at' => $f->created_at?->toIso8601String(),
                    'client' => $f->client ? $f->client->client_code.' · '.$f->client->legal_name : null]),
            'approvals' => $approvals,
            'ledger_check' => LedgerCheck::last(),
            'exchange' => [
                'from' => $fyStart->toDateString(),
                'gain' => round((float) $fx->sum('debit'), 2), 'loss' => round((float) $fx->sum('credit'), 2),
                'lines' => $fx->take(100)->map(fn ($l) => ['date' => $l->transaction_date?->toDateString(), 'reference' => $l->document_reference,
                    'client' => $l->client?->client_code, 'amount' => round((float) $l->debit - (float) $l->credit, 2), 'notes' => $l->notes])->values(),
            ],
            'renewal_quotes' => \App\Services\RenewalQuotes::settings(),
            'quote_first' => ['enabled' => \App\Services\QuoteFirst::enabled(),
                'before_payment' => \App\Models\AuditLog::whereIn('action', ['quote_first_override', 'invoice_before_payment'])->where('created_at', '>=', now()->subDays(90))
                    ->with('user:id,name')->latest('id')->limit(100)->get()->map(fn ($a) => ['at' => $a->created_at?->toIso8601String(), 'action' => $a->action,
                        'by' => $a->user?->name ?? 'Zoho Books', 'what' => $a->metadata['what'] ?? 'Invoice raised in Zoho',
                        'reference' => $a->metadata['uin'] ?? (PatentInvoiceIn::whereKey($a->subject_id)->value('invoice_uin')),
                        'reason' => $a->metadata['reason'] ?? null])],
            'can' => ['unlock' => in_array($request->user()->role, self::UNLOCK_ROLES, true)],
        ]);
    }

    public function lock(Request $request, string $month): JsonResponse
    {
        $this->allow($request);
        abort_unless(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1, 422, 'Month must be YYYY-MM.');
        abort_if($month >= now()->format('Y-m'), 422, 'Only past months can be closed.');
        $note = $request->validate(['note' => 'nullable|string|max:255'])['note'] ?? null;
        DB::transaction(function () use ($month, $note, $request) {
            FinancePeriodLock::firstOrCreate(['month' => $month], ['locked_by_id' => $request->user()->id, 'locked_at' => now(), 'note' => $note]);
            AuditLog::create(['user_id' => $request->user()->id, 'action' => 'finance_month_locked', 'subject_type' => 'FinancePeriodLock', 'subject_id' => 0,
                'metadata' => ['month' => $month, 'note' => $note], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
        });
        FinancePeriod::forget();

        return response()->json(['ok' => true]);
    }

    public function unlock(Request $request, string $month): JsonResponse
    {
        abort_unless(in_array($request->user()->role, self::UNLOCK_ROLES, true), 403, 'Only a partner or super admin can reopen a closed month.');
        $reason = $request->validate(['reason' => 'required|string|max:255'])['reason'];
        DB::transaction(function () use ($month, $reason, $request) {
            FinancePeriodLock::where('month', $month)->delete();
            AuditLog::create(['user_id' => $request->user()->id, 'action' => 'finance_month_unlocked', 'subject_type' => 'FinancePeriodLock', 'subject_id' => 0,
                'metadata' => ['month' => $month, 'reason' => $reason], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
        });
        FinancePeriod::forget();

        return response()->json(['ok' => true]);
    }

    /** The renewal quote-first switch (drafts a renewal quote N days before each annuity). */
    public function renewalQuotes(Request $request): JsonResponse
    {
        $this->allow($request);
        $data = $request->validate(['enabled' => 'required|boolean', 'days' => 'required|integer|min:7|max:365']);
        $saved = \App\Services\RenewalQuotes::save((bool) $data['enabled'], (int) $data['days']);
        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'renewal_auto_quotes_updated', 'subject_type' => 'Finance', 'subject_id' => 0,
            'metadata' => $saved, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        return response()->json($saved);
    }

    /** Mark changes in a locked month reviewed (one, or all of a month). */
    public function review(Request $request): JsonResponse
    {
        $this->allow($request);
        $data = $request->validate(['ids' => 'nullable|array', 'ids.*' => 'integer', 'month' => 'nullable|string|size:7']);
        $query = FinanceLockFlag::whereNull('reviewed_at')
            ->when($data['ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->when($data['month'] ?? null, fn ($q, $m) => $q->where('month', $m));
        abort_unless(! empty($data['ids']) || ! empty($data['month']), 422, 'Pick the changes to mark reviewed.');
        $n = $query->update(['reviewed_by_id' => $request->user()->id, 'reviewed_at' => now()]);
        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'finance_lock_flags_reviewed', 'subject_type' => 'FinanceLockFlag', 'subject_id' => 0,
            'metadata' => $data + ['count' => $n], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        return response()->json(['reviewed' => $n]);
    }

    /** Accounts approves (or rejects) a quote for Zoho; approval is tied to the exact draft. */
    public function decide(Request $request, int $id): JsonResponse
    {
        $this->allow($request);
        $data = $request->validate(['approve' => 'required|boolean', 'note' => 'nullable|string|max:500']);
        $approval = QuoteSendApproval::findOrFail($id);
        abort_unless($approval->status === 'pending', 422, 'This request has already been decided.');
        $approval->update(['status' => $data['approve'] ? 'approved' : 'rejected', 'decided_by_id' => $request->user()->id, 'decided_at' => now(),
            'note' => trim(($approval->note ? $approval->note.' · ' : '').($data['note'] ?? ''), ' ·') ?: null]);
        AuditLog::create(['user_id' => $request->user()->id, 'action' => $data['approve'] ? 'quote_approval_approved' : 'quote_approval_rejected',
            'subject_type' => 'QuoteSendApproval', 'subject_id' => $approval->id, 'metadata' => ['note' => $data['note'] ?? null],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
        if ($approval->requested_by_id && $approval->requested_by_id !== $request->user()->id) {
            $row = $this->approvalRow($approval->fresh());
            Notifier::push([$approval->requested_by_id], 'quote_approval', $data['approve'] ? 'Quote approved for Zoho' : 'Quote not approved',
                "{$request->user()->name} ".($data['approve'] ? 'approved' : 'rejected')." {$row['quote']}".(! empty($data['note']) ? ": {$data['note']}" : '.'), '/financial');
        }

        return response()->json($this->approvalRow($approval->fresh()));
    }

    private function approvalRow(QuoteSendApproval $a, $quote = false): array
    {
        if ($quote === false) {
            $quote = $a->quote_kind === 'in' ? PatentInvoiceIn::find($a->quote_id) : Quotation::find($a->quote_id);
        }
        $client = $quote?->client;

        return ['id' => $a->id, 'kind' => $a->quote_kind, 'quote_id' => $a->quote_id, 'status' => $a->status, 'note' => $a->note,
            'quote' => $quote ? ($quote->invoice_uin ?? $quote->quote_code ?? "#{$a->quote_id}") : "#{$a->quote_id}",
            'amount' => $quote ? (float) ($quote->total_bcy ?? $quote->invoice_amount ?? $quote->total_amount ?? 0) : 0.0,
            'client' => $client ? $client->client_code.' · '.($client->legal_name ?: $client->company_name) : null,
            'requested_by' => $a->requestedBy?->name, 'requested_at' => $a->created_at?->toIso8601String(),
            'decided_by' => $a->decidedBy?->name, 'decided_at' => $a->decided_at?->toIso8601String()];
    }
}
