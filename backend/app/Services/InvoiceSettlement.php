<?php

namespace App\Services;

use App\Jobs\ZohoSettlementWriteback;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\PatentInvoiceIn;
use App\Models\RegisterPayment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Settling a register invoice from the portal: Mark paid (finance / partner / super admin)
 * and Waive off (requested by finance / manager, applied only when a partner or super admin
 * approves). Every step is audited inside its transaction; the result is written back to
 * Zoho Books afterwards (ZohoSettlementWriteback) and the Zoho sync never undoes it.
 */
class InvoiceSettlement
{
    public const PAY_ROLES = ['super_admin', 'partner', 'finance'];

    public const WAIVE_REQUEST_ROLES = ['super_admin', 'partner', 'finance', 'manager'];

    public const WAIVE_APPROVE_ROLES = ['super_admin', 'partner'];

    /** Mark paid: finance roles and anyone on Settings → Financial access. */
    public static function canPay(User $user): bool
    {
        return in_array($user->role, self::PAY_ROLES, true) || \App\Support\DataAccess::onFinanceList($user);
    }

    /** Request a waiver: as mark paid (a Director or System Admin then approves). */
    public static function canRequestWaiver(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'partner', 'finance'], true) || \App\Support\DataAccess::onFinanceList($user);
    }

    public const MODES = ['Bank transfer', 'UPI', 'Cheque', 'Cash', 'Card', 'Other'];

    public function __construct(private readonly ClientLedgerSync $ledger) {}

    /** Record a payment (full or part) against the invoice's balance. */
    public function markPaid(PatentInvoiceIn $invoice, float $amount, string $date, string $mode, ?string $reference, User $user, ?string $ip = null, ?string $agent = null): RegisterPayment
    {
        abort_unless(self::canPay($user), 403, 'Only Accounts, a Director, a System Admin or someone on the Financial access list can mark invoices paid.');

        $payment = DB::transaction(function () use ($invoice, $amount, $date, $mode, $reference, $user, $ip, $agent) {
            $row = PatentInvoiceIn::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertOpenInvoice($row);
            $balance = (float) $row->balance_due;
            abort_if($amount <= 0, 422, 'Enter an amount above zero.');
            abort_if($amount - $balance > 0.009, 422, 'The amount is more than the balance due ('.number_format($balance, 2).').');

            $rate = (float) ($row->exchange_rate ?? 1) ?: 1.0;
            $payment = RegisterPayment::create([
                'patent_invoice_in_id' => $row->id, 'client_id' => $row->client_id, 'source' => 'portal', 'recorded_by_id' => $user->id,
                'zoho_payment_id' => 'portal:'.Str::uuid(), 'payment_date' => Carbon::parse($date)->toDateString(),
                'amount' => round($amount, 2), 'currency' => $row->currency ?: 'INR', 'exchange_rate' => $rate,
                'amount_bcy' => round($amount * $rate, 2), 'payment_mode' => $mode, 'reference' => $reference, 'estimated' => false,
            ]);
            $left = round($balance - $amount, 2);
            $row->update([
                'balance_due' => max(0, $left),
                'payment_status' => $left <= 0.009 ? 'Confirmed' : 'Partially Paid',
                'settlement' => 'paid', 'settled_by_id' => $user->id, 'settled_at' => now(),
                'zoho_writeback' => $row->zoho_invoice_id ? 'pending' : 'not_applicable', 'zoho_writeback_error' => null,
            ]);
            AuditLog::create(['user_id' => $user->id, 'action' => 'mark_paid', 'subject_type' => 'PatentInvoiceIn', 'subject_id' => $row->id,
                'metadata' => ['amount' => $amount, 'date' => $date, 'mode' => $mode, 'reference' => $reference, 'balance_left' => max(0, $left), 'payment_id' => $payment->id],
                'ip_address' => $ip, 'user_agent' => $agent]);

            return $payment;
        });

        $this->afterChange($invoice->fresh());
        if ($invoice->fresh()->zoho_writeback === 'pending') {
            ZohoSettlementWriteback::dispatch($invoice->id, 'payment', $payment->id)->afterCommit();
        }

        return $payment;
    }

    /** Ask for a waiver; partners and super admins are notified to approve it. */
    public function requestWaiver(PatentInvoiceIn $invoice, float $amount, string $reason, User $user, ?string $ip = null, ?string $agent = null): void
    {
        abort_unless(self::canRequestWaiver($user), 403, 'You cannot request a waiver.');
        DB::transaction(function () use ($invoice, $amount, $reason, $user, $ip, $agent) {
            $row = PatentInvoiceIn::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertOpenInvoice($row);
            abort_if($row->settlement === 'waiver_requested', 422, 'A waiver is already waiting for approval.');
            abort_if($amount <= 0 || $amount - (float) $row->balance_due > 0.009, 422, 'The waiver must be above zero and at most the balance due.');
            abort_if(trim($reason) === '', 422, 'Give a reason for the waiver.');
            $row->update(['settlement' => 'waiver_requested', 'waiver_requested_amount' => round($amount, 2), 'waiver_reason' => trim($reason),
                'waiver_requested_by_id' => $user->id, 'waiver_requested_at' => now()]);
            AuditLog::create(['user_id' => $user->id, 'action' => 'waiver_requested', 'subject_type' => 'PatentInvoiceIn', 'subject_id' => $row->id,
                'metadata' => ['amount' => $amount, 'reason' => $reason], 'ip_address' => $ip, 'user_agent' => $agent]);
            User::whereIn('role', self::WAIVE_APPROVE_ROLES)->where('status', 'Active')->where('id', '!=', $user->id)->pluck('id')
                ->each(fn ($id) => Notification::create(['user_id' => $id, 'title' => 'Waiver approval needed',
                    'message' => "{$user->name} asks to waive ".($row->currency ?: 'INR').' '.number_format($amount, 2)." on {$row->invoice_uin}: {$reason}",
                    'channel' => 'in-app', 'is_read' => false, 'action_url' => '/pending-payments?waivers=1']));
        });
        $this->afterChange($invoice->fresh(), false);
    }

    /** Approve (applies the waiver and writes it off in Zoho) or reject a waiver request. */
    public function decideWaiver(PatentInvoiceIn $invoice, bool $approve, User $user, ?string $note = null, ?string $ip = null, ?string $agent = null): void
    {
        abort_unless(in_array($user->role, self::WAIVE_APPROVE_ROLES, true), 403, 'Only a partner or super admin can approve waivers.');
        $writeOff = false;
        DB::transaction(function () use ($invoice, $approve, $user, $note, $ip, $agent, &$writeOff) {
            $row = PatentInvoiceIn::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->settlement === 'waiver_requested', 422, 'There is no waiver waiting on this invoice.');
            abort_if($approve && $row->waiver_requested_by_id === $user->id && $user->role !== 'super_admin', 422, 'A waiver needs a second person to approve it.');
            $amount = (float) $row->waiver_requested_amount;
            if ($approve) {
                $left = round(max(0, (float) $row->balance_due - $amount), 2);
                $writeOff = $left <= 0.009 && (bool) $row->zoho_invoice_id;
                $row->update([
                    'waived_amount' => round((float) $row->waived_amount + $amount, 2), 'balance_due' => $left,
                    'payment_status' => $left <= 0.009 ? 'Waived' : 'Partially Paid', 'settlement' => $left <= 0.009 ? 'waived' : 'paid',
                    'settled_by_id' => $user->id, 'settled_at' => now(), 'waiver_requested_amount' => null,
                    'zoho_writeback' => $row->zoho_invoice_id ? ($writeOff ? 'pending' : 'failed') : 'not_applicable',
                    'zoho_writeback_error' => $row->zoho_invoice_id && ! $writeOff ? 'Zoho writes off whole balances only; record the part waiver in Zoho by hand (credit note).' : null,
                ]);
            } else {
                $row->update(['settlement' => (float) $row->waived_amount > 0 ? 'waived' : (str_contains((string) $row->payment_status, 'Paid') ? 'paid' : null),
                    'waiver_requested_amount' => null]);
            }
            AuditLog::create(['user_id' => $user->id, 'action' => $approve ? 'waiver_approved' : 'waiver_rejected', 'subject_type' => 'PatentInvoiceIn', 'subject_id' => $row->id,
                'metadata' => ['amount' => $amount, 'reason' => $row->waiver_reason, 'note' => $note], 'ip_address' => $ip, 'user_agent' => $agent]);
            if ($row->waiver_requested_by_id) {
                Notification::create(['user_id' => $row->waiver_requested_by_id, 'title' => $approve ? 'Waiver approved' : 'Waiver rejected',
                    'message' => "{$user->name} ".($approve ? 'approved' : 'rejected')." the waiver on {$row->invoice_uin}".($note ? ": {$note}" : '.'),
                    'channel' => 'in-app', 'is_read' => false, 'action_url' => '/pending-payments']);
            }
        });
        $this->afterChange($invoice->fresh());
        if ($writeOff) {
            ZohoSettlementWriteback::dispatch($invoice->id, 'writeoff')->afterCommit();
        }
    }

    private function assertOpenInvoice(PatentInvoiceIn $row): void
    {
        abort_unless($row->type === 'invoice' && ! in_array($row->status, ['Draft', 'Cancelled'], true), 422, 'Only issued invoices can be settled.');
        abort_if((float) $row->balance_due <= 0.009, 422, 'This invoice has nothing left to pay.');
    }

    /** Ledger and cached figures follow the settlement. */
    private function afterChange(?PatentInvoiceIn $row, bool $ledger = true): void
    {
        if ($ledger && $row?->client_id) {
            $this->ledger->sync($row->client_id);
        }
        Cache::increment('dashboard_v');
    }
}
