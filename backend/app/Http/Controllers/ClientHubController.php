<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\DiscussionMessage;
use App\Models\DiscussionThread;
use App\Models\Notification;
use App\Models\PatentApplication;
use App\Models\PatentInvoiceIn;
use App\Models\Project;
use App\Models\RenewalDecision;
use App\Models\RenewalSchedule;
use App\Models\User;
use App\Services\ClientScorecard;
use App\Services\FinanceFigures;
use App\Services\RenewalOutlook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Everything waiting on a client in one place: approvals, quotes to accept, renewal
 * decisions, invoices to pay (with the firm's bank / UPI details), questions on any item,
 * and the monthly scorecard PDF. Client users see their own client; firm staff pass
 * client_id to see (and act for) a client.
 */
class ClientHubController extends Controller
{
    private const STAFF = ['super_admin', 'partner', 'director', 'manager', 'finance'];

    public const PAYMENT_DETAILS_KEY = 'payment_details';

    public function __construct(private readonly FinanceFigures $figures) {}

    public function index(Request $request): JsonResponse
    {
        $client = $this->client($request);
        $user = $request->user();

        // Quotes are listed under quotes below (their approvals live in the Approvals tab).
        $approvals = Approval::with('project:id,docket_number,project_name')->where('client_id', $client->id)->where('type', 'client')
            ->where(fn ($q) => $q->whereNull('subject_type')->orWhere('subject_type', '!=', \App\Services\QuoteApprovals::SUBJECT))
            ->whereRaw('LOWER(status) = ?', ['pending'])->latest()->limit(50)->get()
            ->map(fn ($a) => ['id' => $a->id, 'kind' => $a->kind, 'title' => $a->title, 'description' => $a->description,
                'project_id' => $a->project_id, 'docket_number' => $a->project?->docket_number, 'submitted' => $a->created_at?->toDateString()]);

        $quotes = PatentInvoiceIn::where('type', 'quote')->where('client_id', $client->id)->where('status', 'Sent')
            ->where(fn ($q) => $q->whereNull('zoho_status')->orWhereNotIn(DB::raw('LOWER(zoho_status)'), ['invoiced', 'declined', 'expired']))
            ->orderByDesc('invoice_date')->limit(50)->get()
            ->map(fn ($q) => ['id' => $q->id, 'number' => $q->invoice_uin, 'project_id' => $q->project_id, 'date' => $q->invoice_date?->toDateString(),
                'amount' => (float) ($q->total_bcy ?? $q->invoice_amount), 'currency' => $q->currency ?: 'INR']);

        $decisions = RenewalDecision::where('client_id', $client->id)->get()->keyBy('renewal_schedule_id');
        $titles = PatentApplication::where('client_id', $client->id)->pluck('title', 'id');
        $renewals = RenewalOutlook::nextDue(PatentApplication::where('client_id', $client->id)->select('id'), 500)
            ->filter(fn ($r) => $r['days'] <= 120)
            ->map(function ($r) use ($decisions, $titles) {
                $schedule = RenewalSchedule::find($r['renewal_id'], ['id', 'patent_application_id']);
                $decision = $decisions->get($r['renewal_id']);
                $isUs = strtoupper((string) $r['patent_office_code']) === 'US';

                return $r + [
                    'title' => $schedule ? ($titles[$schedule->patent_application_id] ?? null) : null,
                    'years_left' => $isUs ? null : max(0, 20 - (int) $r['renewal_year']),
                    'decision' => $decision?->decision, 'decision_note' => $decision?->note,
                    'decided_at' => $decision?->updated_at?->toDateString(),
                ];
            })->values();

        $invoices = $this->figures->rows($user, $client->id)->filter(fn ($r) => $r['outstanding'] > 0 && $r['source'] !== 'portal')   // register rows: proof can be sent on them
            ->sortBy(fn ($r) => $r['due_date']?->timestamp ?? PHP_INT_MAX)->take(50)
            ->map(fn ($r) => ['id' => $r['id'], 'number' => $r['number'], 'project_id' => $r['project_id'] ?? null,
                'outstanding' => round($r['outstanding'], 2), 'due_date' => $r['due_date']?->toDateString(),
                'overdue' => $r['due_date'] && $r['due_date']->lt(now()->startOfDay()), 'status' => $r['status'], 'currency' => $r['currency']])->values();

        $proof = PatentInvoiceIn::whereIn('id', $invoices->pluck('id'))->pluck('payment_status', 'id');
        $invoices = $invoices->map(fn ($i) => $i + ['payment_status' => $proof[$i['id']] ?? null]);

        return response()->json([
            'client' => ['id' => $client->id, 'name' => $client->legal_name ?: $client->company_name, 'client_code' => $client->client_code],
            'approvals' => $approvals, 'quotes' => $quotes, 'renewals' => $renewals, 'invoices' => $invoices,
            'counts' => ['approvals' => $approvals->count(), 'quotes' => $quotes->count(),
                'renewals' => $renewals->whereNull('decision')->count(), 'invoices' => $invoices->count()],
            'payment_details' => self::paymentDetails(),
            'can_decide' => in_array($user->role, self::STAFF, true) || ($user->isClientRole() && \App\Support\ClientAccess::can($user, 'approvals')),
            'can_renew' => in_array($user->role, self::STAFF, true) || ($user->isClientRole() && \App\Support\ClientAccess::can($user, 'renewals')),
        ]);
    }

    /** Renew / let lapse / ask us — recorded once per renewal (changeable), the firm is notified. */
    public function decide(Request $request, int $scheduleId): JsonResponse
    {
        $client = $this->client($request);
        $user = $request->user();
        abort_unless(in_array($user->role, self::STAFF, true) || ($user->isClientRole() && \App\Support\ClientAccess::can($user, 'renewals')), 403, 'Your portal access does not include renewal decisions.');
        $data = $request->validate(['decision' => 'required|in:'.implode(',', RenewalDecision::DECISIONS), 'note' => 'nullable|string|max:2000']);
        $schedule = RenewalSchedule::with('application:id,client_id,application_number')->findOrFail($scheduleId);
        abort_unless($schedule->application?->client_id === $client->id, 404);
        abort_unless($schedule->status === 'Unpaid', 422, 'This renewal is already paid.');

        $project = Project::where('patent_application_id', $schedule->patent_application_id)->orderByDesc('id')->first(['id', 'docket_number']);
        $decision = DB::transaction(function () use ($schedule, $client, $user, $data, $request, $project) {
            $decision = RenewalDecision::updateOrCreate(['renewal_schedule_id' => $schedule->id],
                ['client_id' => $client->id, 'decision' => $data['decision'], 'note' => $data['note'] ?? null, 'decided_by_id' => $user->id]);
            AuditLog::create(['user_id' => $user->id, 'action' => 'renewal_decision', 'subject_type' => 'RenewalSchedule', 'subject_id' => $schedule->id,
                'metadata' => ['decision' => $data['decision'], 'note' => $data['note'] ?? null, 'client_id' => $client->id],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
            $words = ['renew' => 'wants to renew', 'lapse' => 'chose to let lapse', 'ask' => 'has a question about'][$data['decision']];
            $label = ($project?->docket_number ?? $schedule->application?->application_number).' · '.$schedule->display_label;
            $this->notifyFirm($client, 'Renewal decision', "{$client->legal_name} {$words} {$label}.".(! empty($data['note']) ? " Note: {$data['note']}" : ''),
                $project ? "/projects/{$project->id}?tab=deadlines" : '/pending-payments');
            if ($data['decision'] === 'ask') {
                $this->thread($client, $user, $project?->id, "Question about renewal {$label}", $data['note'] ?: 'Please advise on this renewal.');
            }

            return $decision;
        });

        return response()->json(['id' => $decision->id, 'decision' => $decision->decision]);
    }

    /** A question on any item (invoice, deadline, stage, renewal, quote) — opens a discussion thread on the case. */
    public function comment(Request $request): JsonResponse
    {
        $client = $this->client($request);
        $data = $request->validate([
            'kind' => 'required|in:invoice,deadline,stage,renewal,quote,case',
            'ref' => 'required|string|max:120',
            'project_id' => 'nullable|integer',
            'message' => 'required|string|max:5000',
        ]);
        $projectId = isset($data['project_id']) && Project::where('id', $data['project_id'])->where('client_id', $client->id)->exists() ? (int) $data['project_id'] : null;
        $thread = DB::transaction(function () use ($client, $request, $projectId, $data) {
            $thread = $this->thread($client, $request->user(), $projectId, 'Question about '.$data['kind'].' '.$data['ref'], $data['message']);
            AuditLog::create(['user_id' => $request->user()->id, 'action' => 'create', 'subject_type' => 'DiscussionThread', 'subject_id' => $thread->id,
                'metadata' => ['kind' => $data['kind'], 'ref' => $data['ref']], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
            if ($request->user()->isClientRole()) {
                $this->notifyFirm($client, 'Client question', "{$client->legal_name} asked about {$data['kind']} {$data['ref']}.", "/discussions?thread={$thread->id}");
            }

            return $thread;
        });

        return response()->json(['thread_id' => $thread->id], 201);
    }

    /** Monthly one-page scorecard PDF. */
    public function scorecard(Request $request, ClientScorecard $scorecard)
    {
        $client = $this->client($request);
        $month = $request->filled('month') ? \Carbon\Carbon::createFromFormat('Y-m', (string) $request->query('month'))->startOfMonth() : now()->startOfMonth();
        $data = $scorecard->build($client, $request->user(), $month);
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.client-scorecard', ['card' => $data])->setPaper('a4');

        return $pdf->download("Monthly-Report-{$client->client_code}-{$month->format('Y-m')}.pdf");
    }

    /** @return array<string, string|null> */
    public static function paymentDetails(): array
    {
        $raw = DB::table('system_settings')->where('key', self::PAYMENT_DETAILS_KEY)->value('value');
        $saved = $raw ? (json_decode($raw, true) ?: []) : [];

        return array_merge(array_fill_keys(['account_name', 'bank_name', 'account_number', 'ifsc', 'swift', 'branch', 'upi_id', 'note'], null), $saved);
    }

    public function updatePaymentDetails(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['super_admin', 'partner', 'finance'], true), 403);
        $data = $request->validate(collect(array_keys(self::paymentDetails()))->mapWithKeys(fn ($k) => [$k => 'nullable|string|max:255'])->all());
        DB::transaction(function () use ($data, $request) {
            DB::table('system_settings')->updateOrInsert(['key' => self::PAYMENT_DETAILS_KEY], ['value' => json_encode($data), 'updated_at' => now()]);
            AuditLog::create(['user_id' => $request->user()->id, 'action' => 'update_payment_details', 'subject_type' => 'SystemSetting', 'subject_id' => 0,
                'metadata' => ['fields' => array_keys(array_filter($data))], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
        });

        return response()->json(self::paymentDetails());
    }

    public function getPaymentDetails(): JsonResponse
    {
        return response()->json(self::paymentDetails());
    }

    private function client(Request $request): Client
    {
        $user = $request->user();
        if ($user->isClientRole()) {
            $client = $request->attributes->get('portal_client') ?? Client::forUser($user);
            abort_unless($client, 403, 'No client record linked to your account.');

            return $client;
        }
        abort_unless(in_array($user->role, self::STAFF, true), 403);
        abort_unless($request->filled('client_id'), 422, 'Choose a client.');

        return Client::findOrFail((int) $request->input('client_id'));
    }

    private function thread(Client $client, User $author, ?int $projectId, string $title, string $message): DiscussionThread
    {
        $thread = DiscussionThread::create(['title' => mb_substr($title, 0, 255), 'tag' => $projectId ? 'Project' : 'General',
            'client_id' => $client->id, 'project_id' => $projectId, 'status' => 'Open']);
        DiscussionMessage::create(['thread_id' => $thread->id, 'author_id' => $author->id, 'content' => $message]);

        return $thread;
    }

    /** In-app notice to the client's account manager and the partners. */
    private function notifyFirm(Client $client, string $title, string $message, string $url): void
    {
        User::where(fn ($q) => $q->where('id', $client->account_manager_id)->orWhereIn('role', ['partner', 'super_admin']))
            ->where('status', 'Active')->pluck('id')->unique()
            ->pipe(fn ($ids) => \App\Support\Notifier::push($ids, 'client', $title, mb_substr($message, 0, 1000), $url));   // the bell (live), not the old table
    }
}
