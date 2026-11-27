<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\PatentInvoiceIn;
use App\Models\Project;
use App\Models\User;
use App\Support\Notifier;

/**
 * A quote sent to a client is an approval waiting on the client: it shows in their Approvals
 * tab and on the dashboard, and approving it from either place does the same thing (the quote
 * becomes Accepted, the approval is resolved, the firm is told). A quote that moves on by other
 * means (invoiced, declined, cancelled) closes its approval.
 */
class QuoteApprovals
{
    public const SUBJECT = 'PatentInvoiceIn';

    /** The client approval for this quote, if any. */
    public static function approvalFor(PatentInvoiceIn $quote): ?Approval
    {
        return Approval::where('type', 'client')->where('subject_type', self::SUBJECT)->where('subject_id', $quote->id)->latest('id')->first();
    }

    /** Make sure a sent quote has a pending client approval (once); tell the client's users when new. */
    public static function ensure(PatentInvoiceIn $quote, bool $notify = true): ?Approval
    {
        if ($quote->type !== 'quote' || ! $quote->client_id || $quote->status !== 'Sent' || $quote->converted_invoice_id) {
            return null;
        }
        if ($existing = self::approvalFor($quote)) {
            return $existing;
        }
        $requester = $quote->created_by_id ?: User::where('role', 'super_admin')->orderBy('id')->value('id');
        if (! $requester) {
            return null;
        }
        $number = $quote->invoice_uin ?: $quote->zoho_number ?: "Q-{$quote->id}";
        $amount = self::amountText($quote);
        $approval = Approval::create([
            'requester_id' => $requester, 'client_id' => $quote->client_id, 'project_id' => $quote->project_id,
            'type' => 'client', 'kind' => 'budget', 'status' => 'Pending',
            'title' => "Approve quote {$number} · {$amount}",
            'description' => trim(($quote->invention_title ?: '').($quote->docket_number ? " ({$quote->docket_number})" : '')).' Approving lets us start the work.',
            'subject_type' => self::SUBJECT, 'subject_id' => $quote->id,
        ]);
        if ($notify) {
            ClientNotifier::push($quote->client_id, 'approval', "Quote {$number} to approve", "We sent quote {$number} for {$amount}. Approve it to let us start the work.", '/approvals', ['approval_id' => $approval->id, 'invoice_id' => $quote->id]);
        }

        return $approval;
    }

    /** Close the approval when the quote is accepted, invoiced, declined or cancelled elsewhere. */
    public static function sync(PatentInvoiceIn $quote): void
    {
        $approval = self::approvalFor($quote);
        if (! $approval || $approval->status !== 'Pending') {
            return;
        }
        $status = match (true) {
            $quote->status === 'Accepted' || (bool) $quote->converted_invoice_id => 'Approved',
            in_array($quote->status, ['Declined', 'Rejected'], true) => 'Rejected',
            in_array($quote->status, ['Cancelled', 'Expired', 'Draft'], true) => 'Cancelled',
            default => null,
        };
        if ($status) {
            $approval->update(['status' => $status]);
        }
    }

    /**
     * The client approves or declines a quote (dashboard or Approvals tab).
     *
     * @param  'Approved'|'Rejected'  $action
     */
    public static function decide(PatentInvoiceIn $quote, string $action, User $user, ?string $comment = null): PatentInvoiceIn
    {
        abort_if($quote->type !== 'quote', 422, 'Only a quote can be approved here.');
        abort_if($quote->status === 'Cancelled', 422, 'This quote has been cancelled.');
        $approval = self::approvalFor($quote) ?? self::ensure($quote, false);
        $quote->update(['status' => $action === 'Approved' ? 'Accepted' : 'Declined']);
        if ($approval && $approval->status === 'Pending') {
            $approval->update(['status' => $action, 'approver_id' => $user->id, 'comments' => $comment]);
        }
        AuditLog::create([
            'user_id' => $user->id, 'action' => $action === 'Approved' ? 'client_approve_quote' : 'client_decline_quote',
            'subject_type' => self::SUBJECT, 'subject_id' => $quote->id,
            'metadata' => ['docket_number' => $quote->docket_number, 'invoice_uin' => $quote->invoice_uin, 'approval_id' => $approval?->id, 'comment' => $comment],
            'ip_address' => request()?->ip(), 'user_agent' => request()?->userAgent(),
        ]);

        $project = $quote->project_id ? Project::find($quote->project_id) : null;
        $client = $quote->client;
        $recipients = collect([$project?->assigned_partner_id, $project?->assigned_manager_id, $project?->secondary_manager_id, $project?->patent_engineer_id,
            $client?->account_manager_id, $approval?->requester_id])
            ->merge(User::whereIn('role', ['super_admin', 'partner', 'director', 'paralegal', 'finance'])->where('status', 'Active')->pluck('id'))
            ->filter()->unique()->values();
        $who = $client?->company_name ?? $client?->legal_name ?? 'The client';
        $number = $quote->invoice_uin ?: $quote->zoho_number;
        Notifier::push($recipients, 'approval', $action === 'Approved' ? "Client approved quote {$number}" : "Client declined quote {$number}",
            "{$who} ".($action === 'Approved' ? 'approved' : 'declined')." quote {$number}".($quote->docket_number ? " for {$quote->docket_number}" : '').'.'
                .($comment ? " Note: {$comment}" : '').($action === 'Approved' ? ' Raise the invoice once paid.' : ''),
            '/pending-payments?doc=quotes', ['invoice_id' => $quote->id, 'project_id' => $project?->id]);
        // The client's other users see it too.
        ClientNotifier::push($quote->client_id, 'approval', "Quote {$number} ".strtolower($action), "{$user->name} ".strtolower($action)." quote {$number}.", '/approvals', ['invoice_id' => $quote->id], [$user->id]);

        return $quote->fresh();
    }

    private static function amountText(PatentInvoiceIn $quote): string
    {
        $currency = $quote->currency ?: 'INR';

        return ($currency === 'INR' ? '₹' : $currency.' ').number_format((float) $quote->invoice_amount, 2);
    }
}
