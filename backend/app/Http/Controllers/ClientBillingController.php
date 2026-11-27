<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\PatentInvoiceIn;
use App\Services\ClientStatement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A client's own billing, from the portal's register (native invoices, payments and
 * proformas; Zoho-raised invoices are imported into it): what they owe, what is unpaid, their
 * statement figures. Used by the client's Invoices & Payments page and dashboard.
 */
class ClientBillingController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isClientRole(), 403);
        $client = $request->attributes->get('portal_client') ?? Client::forUser($user);
        abort_unless($client, 404, 'No client record linked to your account.');

        return response()->json(self::build($client, $user));
    }

    /** @return array<string, mixed> */
    public static function build(Client $client, \App\Models\User $user): array
    {
        $statement = app(ClientStatement::class);
        $lines = $statement->lines($client->id);
        $proformas = $statement->openQuotes($user, $client->id);
        $summary = $statement->summary($lines);
        $summary['quoted'] = round((float) collect($proformas)->sum('net_due'), 2);
        $summary = ClientStatement::withProformas($summary);

        $today = now()->startOfDay();
        $unpaid = PatentInvoiceIn::where('client_id', $client->id)->where('type', 'invoice')->whereNotIn('status', ['Draft', 'Cancelled'])
            ->where('payment_status', '!=', 'Confirmed')->where(fn ($q) => $q->whereNull('balance_due')->orWhere('balance_due', '>', 0))
            ->orderByRaw('due_date ASC NULLS LAST')->limit(200)
            ->get(['id', 'invoice_uin', 'docket_number', 'invention_title', 'invoice_date', 'due_date', 'invoice_amount', 'balance_due', 'currency', 'exchange_rate', 'payment_status'])
            ->map(fn ($i) => ['id' => $i->id, 'number' => $i->invoice_uin, 'docket' => $i->docket_number, 'title' => $i->invention_title,
                'date' => $i->invoice_date?->toDateString(), 'due_date' => $i->due_date?->toDateString(),
                'days_overdue' => $i->due_date && $i->due_date->lt($today) ? (int) $i->due_date->diffInDays($today) : 0,
                'amount' => (float) $i->invoice_amount, 'balance' => (float) ($i->balance_due ?? $i->invoice_amount), 'currency' => $i->currency ?: 'INR',
                'balance_inr' => round((float) ($i->balance_due ?? $i->invoice_amount) * ((float) ($i->exchange_rate ?? 1) ?: 1), 2),
                'status' => $i->payment_status]);

        return [
            'client_id' => $client->id, 'client_code' => $client->client_code,
            'summary' => $summary + ['unpaid_invoices' => round((float) $unpaid->sum('balance_inr'), 2), 'unpaid_count' => $unpaid->count(),
                'overdue' => round((float) $unpaid->where('days_overdue', '>', 0)->sum('balance_inr'), 2)],
            'unpaid' => $unpaid->values(),
            'proformas' => $proformas,
        ];
    }
}
