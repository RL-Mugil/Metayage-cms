<?php

namespace App\Http\Controllers;

use App\Models\AdvanceQuoteLink;
use App\Models\AuditLog;
use App\Models\PatentInvoiceIn;
use App\Services\ClientStatement;
use App\Services\InvoiceSettlement;
use App\Services\PaymentsReceived;
use App\Support\DataAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Payments received (one row per payment) and advances earmarked for proforma quotes. */
class PaymentsReceivedController extends Controller
{
    public function __construct(private PaymentsReceived $payments) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(DataAccess::canOpenFinance($user), 403, 'You do not have access to finance.');
        $filters = $request->validate([
            'search' => 'nullable|string|max:120', 'client_id' => 'nullable|integer', 'from' => 'nullable|date', 'to' => 'nullable|date',
            'unused' => 'nullable|boolean', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:5|max:100',
        ]);
        $all = $this->payments->rows($user);
        $rows = $this->payments->filter($all, $filters);
        $perPage = (int) ($filters['per_page'] ?? 25);
        $page = min((int) ($filters['page'] ?? 1), max(1, (int) ceil($rows->count() / $perPage)));

        return response()->json([
            'data' => $rows->forPage($page, $perPage)->values(),
            'total' => $rows->count(), 'per_page' => $perPage, 'current_page' => $page,
            'last_page' => max(1, (int) ceil($rows->count() / $perPage)), 'has_more' => $page * $perPage < $rows->count(),
            'totals' => [
                'received' => round($rows->sum('amount'), 2), 'tds' => round($rows->sum('tds'), 2),
                'unused' => round($rows->sum('unused'), 2), 'unlinked' => round($rows->sum('unlinked'), 2),
            ],
            'clients' => $all->pluck('client')->filter()->unique('id')->sortBy('code')->values(),
            'can' => ['link' => InvoiceSettlement::canPay($user)],
        ]);
    }

    /** The client's open proforma quotes an advance can be earmarked for. */
    public function quotes(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(DataAccess::canOpenFinance($user), 403, 'You do not have access to finance.');
        $clientId = (int) $request->validate(['client_id' => 'required|integer'])['client_id'];

        return response()->json(app(ClientStatement::class)->openQuotes($user, $clientId));
    }

    public function link(Request $request, string $paymentId): JsonResponse
    {
        $user = $request->user();
        abort_unless(InvoiceSettlement::canPay($user), 403, 'Only Accounts, a Director, a System Admin or someone on the Financial access list can link advances.');
        $data = $request->validate(['quote_id' => 'required|integer', 'amount' => 'required|numeric|min:0.01', 'note' => 'nullable|string|max:255']);
        \App\Support\FinancePeriod::assertOpen(now());
        $advance = $this->payments->advance($paymentId);
        abort_if(! $advance || $advance['unused'] <= 0, 422, 'This payment has no unused amount to link.');
        abort_if(! $advance['client_id'], 422, 'This payment is not linked to a portal client yet. Link the Zoho customer to the client first.');

        $quote = PatentInvoiceIn::where('type', 'quote')->findOrFail($data['quote_id']);
        $open = collect(app(ClientStatement::class)->openQuotes($user, (int) $advance['client_id']));
        abort_unless((int) $quote->client_id === (int) $advance['client_id'] && $open->contains('id', $quote->id), 422,
            'Pick an open (sent, not invoiced) quote of the same client.');

        $amount = round((float) $data['amount'], 2);
        $link = DB::transaction(function () use ($paymentId, $quote, $advance, $amount, $data, $user, $request, $open) {
            // Lock this payment's links (Postgres can't lock an aggregate), then add them up.
            $others = (float) AdvanceQuoteLink::where('zoho_payment_id', $paymentId)->where('patent_invoice_in_id', '!=', $quote->id)
                ->lockForUpdate()->get(['amount'])->sum('amount');
            abort_if($amount > round($advance['unused'] - $others, 2) + 0.009, 422, 'More than the unused amount still free on this payment (₹'.number_format(max(0, $advance['unused'] - $others), 2).').');
            $quoteAmount = (float) ($open->firstWhere('id', $quote->id)['amount'] ?? 0);
            $onQuote = (float) AdvanceQuoteLink::where('patent_invoice_in_id', $quote->id)->where('zoho_payment_id', '!=', $paymentId)->sum('amount');
            abort_if($amount > round($quoteAmount - $onQuote, 2) + 0.009, 422, 'More than the quote still needs (₹'.number_format(max(0, $quoteAmount - $onQuote), 2).').');

            $link = AdvanceQuoteLink::updateOrCreate(
                ['zoho_payment_id' => $paymentId, 'patent_invoice_in_id' => $quote->id],
                ['payment_number' => $advance['number'], 'client_id' => $advance['client_id'], 'amount' => $amount, 'note' => $data['note'] ?? null, 'created_by_id' => $user->id]
            );
            AuditLog::create(['user_id' => $user->id, 'action' => 'link_advance_to_quote', 'subject_type' => 'PatentInvoiceIn', 'subject_id' => $quote->id,
                'metadata' => ['payment' => $advance['number'], 'zoho_payment_id' => $paymentId, 'amount' => $amount],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

            return $link;
        });

        return response()->json($link, 201);
    }

    public function unlink(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless(InvoiceSettlement::canPay($user), 403, 'Only Accounts, a Director, a System Admin or someone on the Financial access list can link advances.');
        $link = AdvanceQuoteLink::findOrFail($id);
        \App\Support\FinancePeriod::assertOpen($link->created_at);
        DB::transaction(function () use ($link, $user, $request) {
            AuditLog::create(['user_id' => $user->id, 'action' => 'unlink_advance_from_quote', 'subject_type' => 'PatentInvoiceIn', 'subject_id' => $link->patent_invoice_in_id,
                'metadata' => ['payment' => $link->payment_number, 'zoho_payment_id' => $link->zoho_payment_id, 'amount' => (float) $link->amount],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
            $link->delete();
        });

        return response()->json(['ok' => true]);
    }
}
