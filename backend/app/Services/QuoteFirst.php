<?php

namespace App\Services;

use App\Models\AdvanceQuoteLink;
use App\Models\AuditLog;
use App\Models\PatentInvoiceIn;
use App\Models\RegisterPayment;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Quote-first billing (Settings → System, super admin): a quote (proforma) is the receivable and
 * becomes a tax invoice only once the client has paid it, so GST is never due before the money is
 * in. Exempt clients (preferred, e.g. 296M) may be invoiced before payment. Enforcement is a
 * warning: staff may go ahead with a reason, which is audited.
 */
class QuoteFirst
{
    public const KEY = 'quote_first_billing';

    /** Clients usually keep TDS (194J, 10%) on professional fees when paying: that shortfall still counts as paid. */
    public const TDS_ALLOWANCE = 0.10;

    /** @return array{enabled: bool, exempt_client_ids: array<int, int>, enabled_at: ?string} */
    public static function settings(): array
    {
        return Cache::rememberForever(self::KEY, function () {
            $s = json_decode((string) DB::table('system_settings')->where('key', self::KEY)->value('value'), true) ?: [];

            return ['enabled' => (bool) ($s['enabled'] ?? false), 'exempt_client_ids' => array_values(array_map('intval', $s['exempt_client_ids'] ?? [])),
                'enabled_at' => $s['enabled_at'] ?? null];
        });
    }

    public static function save(bool $enabled, array $exempt): array
    {
        $before = self::settings();
        $value = ['enabled' => $enabled, 'exempt_client_ids' => array_values(array_unique(array_map('intval', $exempt))),
            'enabled_at' => $enabled ? ($before['enabled'] ? $before['enabled_at'] : now()->toIso8601String()) : null];
        DB::table('system_settings')->updateOrInsert(['key' => self::KEY], ['value' => json_encode($value), 'updated_at' => now()]);
        Cache::forget(self::KEY);

        return self::settings();
    }

    public static function enabled(): bool
    {
        return self::settings()['enabled'];
    }

    public static function exempt(?int $clientId): bool
    {
        return $clientId !== null && in_array($clientId, self::settings()['exempt_client_ids'], true);
    }

    /** The rule applies to this client right now. */
    public static function applies(?int $clientId): bool
    {
        return self::enabled() && ! self::exempt($clientId);
    }

    /**
     * How much of a quote has been paid (INR): advances earmarked for it and any payment recorded on it.
     *
     * @return array{total: float, received: float, allowance: float, due: float, fully_paid: bool}
     */
    public static function paidState(PatentInvoiceIn $quote): array
    {
        $rate = (float) ($quote->exchange_rate ?? 1) ?: 1.0;
        $total = round((float) ($quote->total_bcy ?? (float) $quote->invoice_amount * $rate), 2);
        $received = round((float) AdvanceQuoteLink::where('patent_invoice_in_id', $quote->id)->sum('amount')
            + (float) RegisterPayment::where('patent_invoice_in_id', $quote->id)->sum('amount_bcy'), 2);
        $allowance = round(max(0, (float) $quote->service_fees - (float) $quote->discount_amount) * $rate * self::TDS_ALLOWANCE, 2);

        return ['total' => $total, 'received' => $received, 'allowance' => $allowance, 'due' => round(max(0, $total - $received), 2),
            'fully_paid' => $total > 0 && $received + $allowance + 0.5 >= $total];
    }

    /**
     * Invoice a quote (or raise an invoice) for a client: refuse with code "quote_first" unless the
     * rule does not apply, the quote is paid, or a reason is given (then audited as an override).
     */
    public static function check(?int $clientId, ?PatentInvoiceIn $quote, ?string $overrideReason, User $user, string $what): void
    {
        if (! self::applies($clientId)) {
            return;
        }
        $state = $quote ? self::paidState($quote) : ['total' => 0.0, 'received' => 0.0, 'allowance' => 0.0, 'due' => 0.0, 'fully_paid' => false];
        if ($state['fully_paid']) {
            return;
        }
        $reason = trim((string) $overrideReason);
        if (mb_strlen($reason) < 10) {
            throw new HttpResponseException(response()->json([
                'code' => 'quote_first',
                'message' => 'Quote-first billing: this client has not paid '.($quote ? 'this quote' : 'yet').' (received ₹'.number_format($state['received'], 2)
                    .' of ₹'.number_format($state['total'], 2).'). A tax invoice before payment makes GST payable now. Give a reason to go ahead anyway.',
                'state' => $state,
            ], 422));
        }
        AuditLog::create(['user_id' => $user->id, 'action' => 'quote_first_override', 'subject_type' => 'PatentInvoiceIn', 'subject_id' => $quote?->id ?? 0,
            'metadata' => ['what' => $what, 'client_id' => $clientId, 'reason' => $reason, 'state' => $state],
            'ip_address' => request()?->ip(), 'user_agent' => request()?->userAgent()]);
    }
}
