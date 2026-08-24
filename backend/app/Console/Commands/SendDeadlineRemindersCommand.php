<?php

namespace App\Console\Commands;

use App\Models\Approval;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\RenewalSchedule;
use App\Models\Task;
use App\Models\TrackerRow;
use App\Models\User;
use App\Services\ReminderThresholdResolver;
use App\Support\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SendDeadlineRemindersCommand extends Command
{
    protected $signature = 'reminders:send-deadlines';
    protected $description = 'Notify assignees of upcoming/overdue tracker, task and project deadlines, aging pending approvals, and auto-escalate overdue matters to directors/HR/system admins. Also materialises them as reminders.';

    // Days-ahead thresholds for "upcoming" alerts (tracker/task alerts — not
    // client-scoped, see scanTracker()/scanTasks(); scanProjects() and
    // scanRenewals() resolve per-client thresholds via ReminderThresholdResolver).
    private const THRESHOLDS = [1, 3, 7];
    // Days a client/colleague approval may sit Pending before it is chased.
    private const APPROVAL_AGING_DAYS = 3;
    // Overdue matters are auto-escalated to these designations (job titles)…
    private const ESCALATION_DESIGNATIONS = ['Director', 'HR', 'System Admin'];
    // …and to these login roles (directors ≈ partner in this system).
    private const ESCALATION_ROLES = ['super_admin', 'partner', 'hr'];

    private int $sent = 0;
    private ?array $escalationIds = null;

    public function handle(): int
    {
        $today = Carbon::today();

        $this->scanTracker($today);
        $this->scanTasks($today);
        $this->scanProjects($today);
        $this->scanRenewals($today);
        $this->scanPaymentAnomalies($today);
        $this->scanAgingApprovals();

        $this->info("Sent {$this->sent} deadline notifications / reminders.");
        return 0;
    }

    /**
     * Send an in-app notification (deduped) AND upsert a Reminder row so the
     * item also appears on the Reminders page. A user's "completed" flag on an
     * existing auto-reminder is preserved.
     */
    private function remind(
        ?int $userId,
        string $type,
        string $title,
        string $description,
        string $actionUrl,
        array $meta,
        string $entityKey,   // stable per entity, e.g. "task:12" — the reminder row key
        string $bucket,      // "d3", "overdue", "aging" — distinguishes notification events
        ?string $dueDate,
        string $category,
        int $windowDays = 1  // suppress duplicate notifications within this many days
    ): void {
        if (! $userId) return;

        $notifSource = "{$entityKey}:{$bucket}";

        $already = DB::table('ip_notifications')
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays($windowDays))
            ->where('meta->notif_source', $notifSource)
            ->exists();

        if (! $already) {
            Notifier::push($userId, $type, $title, $description, $actionUrl, $meta + ['notif_source' => $notifSource]);
            $this->sent++;
        }

        // Materialise to the Reminders page (idempotent on user_id + source).
        $reminder = Reminder::firstOrNew(['user_id' => $userId, 'source' => $entityKey]);
        $reminder->title       = $title;
        $reminder->description = $description;
        $reminder->category    = $category;
        $reminder->due_date    = $dueDate;
        $reminder->scope       = 'self';
        if (! $reminder->exists) {
            $reminder->completed = false; // don't un-complete something the user cleared
        }
        $reminder->save();
    }

    /**
     * Directors, HRs and System Admins who oversee overdue matters. Resolved
     * by BOTH login role and employee designation so no one is missed.
     */
    private function escalationRecipients(): array
    {
        if ($this->escalationIds !== null) return $this->escalationIds;

        $byRole = User::whereIn('role', self::ESCALATION_ROLES)
            ->where('status', 'Active')
            ->pluck('id')->all();

        $byDesignation = Employee::whereNotNull('user_id')
            ->whereHas('designation', fn ($q) => $q->whereIn('title', self::ESCALATION_DESIGNATIONS))
            ->pluck('user_id')->all();

        return $this->escalationIds = array_values(array_unique(array_merge($byRole, $byDesignation)));
    }

    /** Escalate one overdue matter to every escalation recipient (deduped 3-daily). */
    private function escalate(string $entityKey, string $label, int $daysLate, string $actionUrl, array $meta, ?string $dueDate): void
    {
        foreach ($this->escalationRecipients() as $uid) {
            $this->remind(
                (int) $uid,
                'deadline',
                "ESCALATION — {$daysLate}d overdue: {$label}",
                "Overdue matter {$label} is {$daysLate} day(s) past its deadline and has been escalated to you for oversight.",
                $actionUrl,
                $meta + ['escalated' => true, 'days_overdue' => $daysLate],
                "escalation:{$entityKey}:{$uid}",
                'overdue',
                $dueDate,
                'Deadline',
                3,
            );
        }
    }

    /**
     * Escalate an *upcoming* deadline (not yet overdue) — the 2-month/1-month
     * standard cadence described on the call, distinct from escalate()'s
     * overdue-triggered wording. Deduped per threshold via the entityKey
     * (includes $daysRemaining) so the 2-month and 1-month escalations are
     * each sent once, not suppressed by each other.
     */
    private function escalateUpcoming(string $entityKey, string $label, int $daysRemaining, string $actionUrl, array $meta, ?string $dueDate): void
    {
        foreach ($this->escalationRecipients() as $uid) {
            $this->remind(
                (int) $uid,
                'deadline',
                "ESCALATION — due in {$daysRemaining}d: {$label}",
                "{$label} is due in {$daysRemaining} day(s) and has been escalated to you for oversight ahead of the deadline.",
                $actionUrl,
                $meta + ['escalated' => true, 'days_remaining' => $daysRemaining],
                "escalation-upcoming:{$entityKey}:{$uid}",
                "upcoming-d{$daysRemaining}",
                $dueDate,
                'Deadline',
                30, // a given 2-month/1-month threshold fires once — no need to re-suppress for a month
            );
        }
    }

    /* ───────────────────────── Tracker rows ───────────────────────── */

    private function scanTracker(Carbon $today): void
    {
        foreach (self::THRESHOLDS as $days) {
            $targetDate = $today->copy()->addDays($days)->toDateString();
            $rows = TrackerRow::whereDate('delivery_due_date', $targetDate)
                ->whereNotNull('client_name')->get();

            foreach ($rows as $row) {
                foreach (['pcm_id' => 'PCM', 'scm_id' => 'SCM', 'pr_id' => 'PR'] as $fkCol => $roleLabel) {
                    if (! $row->$fkCol) continue;
                    $docket = $row->docket_number ?? $row->client_name ?? 'Case';
                    $dueStr = Carbon::parse($row->delivery_due_date)->format('d M Y');
                    $this->remind(
                        (int) $row->$fkCol,
                        'deadline',
                        $days === 1 ? "Due Tomorrow: {$docket}" : "Due in {$days} Days: {$docket}",
                        "Delivery deadline for {$row->client_name}"
                            . ($row->docket_number ? " ({$row->docket_number})" : '')
                            . " is on {$dueStr}. You are assigned as " . strtoupper($roleLabel) . ".",
                        '/tracker',
                        [
                            'tracker_row_id'    => $row->id,
                            'docket_number'     => $row->docket_number,
                            'client_name'       => $row->client_name,
                            'delivery_due_date' => $row->delivery_due_date?->toDateString(),
                            'days_remaining'    => $days,
                            'role'              => $roleLabel,
                        ],
                        "tracker:{$row->id}:{$fkCol}",
                        "d{$days}",
                        $row->delivery_due_date?->toDateString(),
                        'Deadline',
                    );
                }
            }
        }

        // Overdue — chase at most every 3 days.
        $overdue = TrackerRow::whereNotNull('delivery_due_date')
            ->whereDate('delivery_due_date', '<', $today)->get();

        foreach ($overdue as $row) {
            foreach (['pcm_id' => 'PCM', 'scm_id' => 'SCM', 'pr_id' => 'PR'] as $fkCol => $roleLabel) {
                if (! $row->$fkCol) continue;
                $daysLate = (int) Carbon::parse($row->delivery_due_date)->diffInDays($today);
                $docket   = $row->docket_number ?? $row->client_name ?? 'Case';
                $this->remind(
                    (int) $row->$fkCol,
                    'deadline',
                    "OVERDUE: {$docket}",
                    "Delivery for {$row->client_name}"
                        . ($row->docket_number ? " ({$row->docket_number})" : '')
                        . " was due {$daysLate} day(s) ago. You are assigned as {$roleLabel}.",
                    '/tracker',
                    [
                        'tracker_row_id'    => $row->id,
                        'docket_number'     => $row->docket_number,
                        'client_name'       => $row->client_name,
                        'delivery_due_date' => $row->delivery_due_date?->toDateString(),
                        'days_remaining'    => 0,
                        'days_overdue'      => $daysLate,
                        'role'              => $roleLabel,
                    ],
                    "tracker:{$row->id}:{$fkCol}",
                    'overdue',
                    $row->delivery_due_date?->toDateString(),
                    'Deadline',
                    3,
                );
            }

            // Auto-escalate the overdue matter to directors, HRs and System Admins.
            $this->escalate(
                "tracker:{$row->id}",
                $row->docket_number ?? $row->client_name ?? 'Case',
                (int) Carbon::parse($row->delivery_due_date)->diffInDays($today),
                '/tracker',
                [
                    'tracker_row_id' => $row->id,
                    'docket_number'  => $row->docket_number,
                    'client_name'    => $row->client_name,
                ],
                $row->delivery_due_date?->toDateString(),
            );
        }
    }

    /* ───────────────────────── Tasks ───────────────────────── */

    private function scanTasks(Carbon $today): void
    {
        $done = ['Completed', 'Cancelled'];

        foreach (self::THRESHOLDS as $days) {
            $targetDate = $today->copy()->addDays($days)->toDateString();
            $tasks = Task::with('project:id,docket_number')
                ->whereNotNull('due_date')
                ->whereDate('due_date', $targetDate)
                ->whereNotIn('status', $done)
                ->get();

            foreach ($tasks as $task) {
                foreach (array_unique(array_filter([$task->assignee_id, $task->reviewer_id])) as $uid) {
                    $docket = $task->project?->docket_number;
                    $this->remind(
                        (int) $uid,
                        'deadline',
                        $days === 1 ? "Task due tomorrow: {$task->title}" : "Task due in {$days} days: {$task->title}",
                        "\"{$task->title}\"" . ($docket ? " ({$docket})" : '')
                            . " is due on " . Carbon::parse($task->due_date)->format('d M Y') . ".",
                        '/tasks',
                        ['task_id' => $task->id, 'days_remaining' => $days],
                        "task:{$task->id}:{$uid}",
                        "d{$days}",
                        Carbon::parse($task->due_date)->toDateString(),
                        'Deadline',
                    );
                }
            }
        }

        $overdue = Task::with('project:id,docket_number')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today)
            ->whereNotIn('status', $done)
            ->get();

        foreach ($overdue as $task) {
            foreach (array_unique(array_filter([$task->assignee_id, $task->reviewer_id])) as $uid) {
                $daysLate = (int) Carbon::parse($task->due_date)->diffInDays($today);
                $this->remind(
                    (int) $uid,
                    'deadline',
                    "OVERDUE task: {$task->title}",
                    "\"{$task->title}\" was due {$daysLate} day(s) ago and is still {$task->status}.",
                    '/tasks',
                    ['task_id' => $task->id, 'days_overdue' => $daysLate],
                    "task:{$task->id}:{$uid}",
                    'overdue',
                    Carbon::parse($task->due_date)->toDateString(),
                    'Deadline',
                    3,
                );
            }

            // Auto-escalate the overdue task to directors, HRs and System Admins.
            $daysLate = (int) Carbon::parse($task->due_date)->diffInDays($today);
            $docket   = $task->project?->docket_number;
            $this->escalate(
                "task:{$task->id}",
                $task->title . ($docket ? " ({$docket})" : '') . " [task]",
                $daysLate,
                '/tasks',
                ['task_id' => $task->id],
                Carbon::parse($task->due_date)->toDateString(),
            );
        }
    }

    /* ───────────────────────── Projects (hard legal deadlines) ───────────────────────── */

    private function scanProjects(Carbon $today): void
    {
        $done = ['Completed', 'Archived'];
        $team = ['assigned_partner_id', 'assigned_manager_id', 'patent_engineer_id'];
        $resolver = app(ReminderThresholdResolver::class);
        $clientsById = Client::query()->get(['id', 'reminder_cadence_override'])->keyBy('id');

        // Widest possible window (client overrides capped at 365 days —
        // StoreClientRequest/UpdateClientRequest) — each project is then
        // matched against its own client's resolved threshold set.
        $upcoming = Project::whereNotNull('hard_deadline')
            ->whereDate('hard_deadline', '>=', $today->toDateString())
            ->whereDate('hard_deadline', '<=', $today->copy()->addDays(365)->toDateString())
            ->whereNotIn('status', $done)
            ->get();

        foreach ($upcoming as $project) {
            $client = $project->client_id ? $clientsById->get($project->client_id) : null;
            $thresholds = $resolver->thresholdsFor(self::THRESHOLDS, $client);
            $days = (int) $today->diffInDays(Carbon::parse($project->hard_deadline));

            if (! in_array($days, $thresholds, true)) {
                continue;
            }

            $label = $project->docket_number ?? $project->project_name ?? 'Case';
            foreach (array_unique(array_filter(array_map(fn ($c) => $project->$c, $team))) as $uid) {
                $this->remind(
                    (int) $uid,
                    'deadline',
                    $days === 1 ? "Case deadline tomorrow: {$label}" : "Case deadline in {$days} days: {$label}",
                    "Hard deadline for {$label} is on "
                        . Carbon::parse($project->hard_deadline)->format('d M Y') . ". Missing it can forfeit the IP right.",
                    "/projects/{$project->id}",
                    ['project_id' => $project->id, 'days_remaining' => $days],
                    "project:{$project->id}:{$uid}",
                    "d{$days}",
                    Carbon::parse($project->hard_deadline)->toDateString(),
                    'Deadline',
                );
            }
        }

        // Standard firm-wide escalation cadence (system_settings, see
        // ReminderThresholdResolver::escalationCadence()): no escalation
        // beyond ~3 months out, an escalation at the 2-month mark, another
        // at the 1-month mark — ahead of the deadline, distinct from the
        // overdue-triggered escalate() below.
        $cadence = $resolver->escalationCadence();
        foreach ($upcoming as $project) {
            $days = (int) $today->diffInDays(Carbon::parse($project->hard_deadline));
            if ($days > $cadence['none_beyond_days']) {
                continue;
            }
            if (! in_array($days, [$cadence['at_2month_days'], $cadence['at_1month_days']], true)) {
                continue;
            }
            $label = $project->docket_number ?? $project->project_name ?? 'Case';
            $this->escalateUpcoming(
                "project-upcoming:{$project->id}:{$days}",
                $label,
                $days,
                "/projects/{$project->id}",
                ['project_id' => $project->id, 'days_remaining' => $days],
                Carbon::parse($project->hard_deadline)->toDateString(),
            );
        }

        $overdue = Project::whereNotNull('hard_deadline')
            ->whereDate('hard_deadline', '<', $today)
            ->whereNotIn('status', $done)
            ->get();

        foreach ($overdue as $project) {
            $label = $project->docket_number ?? $project->project_name ?? 'Case';
            $daysLate = (int) Carbon::parse($project->hard_deadline)->diffInDays($today);
            foreach (array_unique(array_filter(array_map(fn ($c) => $project->$c, $team))) as $uid) {
                $this->remind(
                    (int) $uid,
                    'deadline',
                    "OVERDUE case deadline: {$label}",
                    "Hard deadline for {$label} passed {$daysLate} day(s) ago. Escalate immediately.",
                    "/projects/{$project->id}",
                    ['project_id' => $project->id, 'days_overdue' => $daysLate],
                    "project:{$project->id}:{$uid}",
                    'overdue',
                    Carbon::parse($project->hard_deadline)->toDateString(),
                    'Deadline',
                    3,
                );
            }

            // Auto-escalate the overdue matter to directors, HRs and System Admins.
            $this->escalate(
                "project:{$project->id}",
                $label,
                $daysLate,
                "/projects/{$project->id}",
                ['project_id' => $project->id],
                Carbon::parse($project->hard_deadline)->toDateString(),
            );
        }
    }

    /* ───────────────────────── Renewal fees ───────────────────────── */

    /**
     * Renewal-fee reminders — the core ask from the call (Niramai wants an
     * extra 1-month-before reminder on top of the standard 6-month/3-month
     * ones). Base cadence is 180/90 days (6mo/3mo); each client's
     * reminder_cadence_override adds to that (e.g. Niramai's extra 30);
     * the jurisdiction-aware payment lead time (Indian ~1wk, foreign ~2wk —
     * ReminderThresholdResolver::renewalLeadDaysFor()) is folded in as a
     * "money needs to move now" threshold distinct from the informational
     * 6/3-month ones. Recipients: the linked project's assigned team, or the
     * client's account manager if no project is linked.
     */
    private function scanRenewals(Carbon $today): void
    {
        $resolver = app(ReminderThresholdResolver::class);
        $baseCadence = [180, 90];

        $upcoming = RenewalSchedule::with('application.client', 'application.projects')
            ->where('status', 'Unpaid')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '>=', $today->toDateString())
            ->whereDate('due_date', '<=', $today->copy()->addDays(365)->toDateString())
            ->get();

        foreach ($upcoming as $renewal) {
            $application = $renewal->application;
            $client = $application?->client;
            $leadDays = $resolver->renewalLeadDaysFor($application?->jurisdiction);
            $thresholds = $resolver->thresholdsFor(array_merge($baseCadence, [$leadDays]), $client);
            $days = (int) $today->diffInDays(Carbon::parse($renewal->due_date));

            if (! in_array($days, $thresholds, true)) {
                continue;
            }

            $project = $application?->projects?->first();
            $label = $project?->docket_number ?? $application?->application_number ?? "Renewal Y{$renewal->renewal_year}";
            $recipients = collect([
                $project?->assigned_partner_id,
                $project?->assigned_manager_id,
                $project?->patent_engineer_id,
                $client?->account_manager_id,
            ])->filter()->unique();

            if ($recipients->isEmpty()) {
                continue;
            }

            $urgent = $days <= $leadDays;
            $title = $urgent
                ? "Renewal payment window: {$label} (Y{$renewal->renewal_year}) — {$days}d left"
                : "Renewal due in {$days} days: {$label} (Y{$renewal->renewal_year})";
            $desc = $urgent
                ? "Year {$renewal->renewal_year} renewal for {$label} is due in {$days} day(s) — within the "
                    . ($application?->jurisdiction === 'IN' ? 'Indian' : 'foreign attorney') . " payment lead window ({$leadDays}d). Get payment moving now."
                : "Year {$renewal->renewal_year} renewal fee for {$label} is due in {$days} day(s).";

            foreach ($recipients as $uid) {
                $this->remind(
                    (int) $uid,
                    'renewal',
                    $title,
                    $desc,
                    $project ? "/projects/{$project->id}" : '/pending-payments',
                    ['renewal_id' => $renewal->id, 'days_remaining' => $days],
                    "renewal:{$renewal->id}:{$uid}",
                    "d{$days}",
                    Carbon::parse($renewal->due_date)->toDateString(),
                    'Deadline',
                );
            }
        }

        // Overdue unpaid renewals — abandonment risk, chase every 3 days.
        $overdue = RenewalSchedule::with('application.client', 'application.projects')
            ->where('status', 'Unpaid')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today->toDateString())
            ->get();

        foreach ($overdue as $renewal) {
            $application = $renewal->application;
            $project = $application?->projects?->first();
            $label = $project?->docket_number ?? $application?->application_number ?? "Renewal Y{$renewal->renewal_year}";
            $daysLate = (int) Carbon::parse($renewal->due_date)->diffInDays($today);
            $recipients = collect([
                $project?->assigned_partner_id,
                $project?->assigned_manager_id,
                $project?->patent_engineer_id,
                $application?->client?->account_manager_id,
            ])->filter()->unique();

            foreach ($recipients as $uid) {
                $this->remind(
                    (int) $uid,
                    'renewal',
                    "OVERDUE renewal ({$daysLate}d): {$label} (Y{$renewal->renewal_year})",
                    "Year {$renewal->renewal_year} renewal for {$label} is {$daysLate} day(s) overdue — risk of abandonment/lapse.",
                    $project ? "/projects/{$project->id}" : '/pending-payments',
                    ['renewal_id' => $renewal->id, 'days_overdue' => $daysLate],
                    "renewal:{$renewal->id}:{$uid}",
                    'overdue',
                    Carbon::parse($renewal->due_date)->toDateString(),
                    'Deadline',
                    3,
                );
            }

            $this->escalate(
                "renewal:{$renewal->id}",
                "{$label} (Y{$renewal->renewal_year}) [renewal]",
                $daysLate,
                $project ? "/projects/{$project->id}" : '/pending-payments',
                ['renewal_id' => $renewal->id],
                Carbon::parse($renewal->due_date)->toDateString(),
            );
        }
    }

    /* ───────────────────────── Payment-pattern anomaly escalation ───────────────────────── */

    /**
     * A client manager configures how many days before a due date a client
     * typically clears payment (Client.payment_clearance_pattern.lead_days).
     * If ~70% of that lead window has elapsed with the renewal still unpaid,
     * something's off the client's usual pattern — escalate early rather
     * than waiting for the standard cadence. Mirrors the call's example:
     * usually clears 2 weeks (14d) before → escalate around 10 days before
     * if still unpaid (round(14 * 5/7) = 10).
     */
    private function scanPaymentAnomalies(Carbon $today): void
    {
        // Per-client escalateAtDays, precomputed once (0 or negative lead_days excluded).
        $clients = Client::whereNotNull('payment_clearance_pattern')
            ->get(['id', 'company_name', 'payment_clearance_pattern'])
            ->map(function ($client) {
                $leadDays = (int) ($client->payment_clearance_pattern['lead_days'] ?? 0);
                $client->escalate_at_days = $leadDays > 0 ? (int) round($leadDays * 5 / 7) : null;
                $client->lead_days = $leadDays;
                return $client;
            })
            ->filter(fn ($client) => $client->escalate_at_days !== null);

        if ($clients->isEmpty()) {
            return;
        }

        // Single batched query for every candidate client instead of one query per
        // client — window covers the widest escalate_at_days across all of them.
        $maxWindow = (int) $clients->max('escalate_at_days');
        $clientsById = $clients->keyBy('id');

        $renewals = RenewalSchedule::with('application.projects')
            ->where('status', 'Unpaid')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '>=', $today->toDateString())
            ->whereDate('due_date', '<=', $today->copy()->addDays($maxWindow)->toDateString())
            ->whereHas('application', fn ($q) => $q->whereIn('client_id', $clientsById->keys()))
            ->get();

        foreach ($renewals as $renewal) {
            $clientId = $renewal->application?->client_id;
            $client = $clientId ? $clientsById->get($clientId) : null;
            if (! $client) {
                continue;
            }

            $days = (int) $today->diffInDays(Carbon::parse($renewal->due_date));
            if ($days !== $client->escalate_at_days) {
                continue;
            }

            $project = $renewal->application?->projects?->first();
            $label = $project?->docket_number ?? $renewal->application?->application_number ?? "Renewal Y{$renewal->renewal_year}";

            $this->escalateUpcoming(
                "renewal-anomaly:{$renewal->id}",
                "{$label} — {$client->company_name} usually pays {$client->lead_days}d ahead, still unpaid",
                $days,
                $project ? "/projects/{$project->id}" : '/pending-payments',
                ['renewal_id' => $renewal->id, 'client_id' => $client->id, 'pattern_lead_days' => $client->lead_days],
                Carbon::parse($renewal->due_date)->toDateString(),
            );
        }
    }

    /* ───────────────────────── Aging pending approvals ───────────────────────── */

    private function scanAgingApprovals(): void
    {
        $cutoff = now()->subDays(self::APPROVAL_AGING_DAYS);

        $stale = Approval::whereIn('type', ['client', 'colleague'])
            ->where('status', 'Pending')
            ->where('created_at', '<', $cutoff)
            ->get();

        $todayStr = now()->toDateString();

        foreach ($stale as $a) {
            $ageDays = (int) Carbon::parse($a->created_at)->diffInDays(now());
            $title   = "Approval pending {$ageDays} days: {$a->title}";

            // Chase the approver(s).
            if ($a->type === 'colleague') {
                $this->remind(
                    (int) $a->approver_id,
                    'approval',
                    $title,
                    "You have a pending approval \"{$a->title}\" awaiting your response for {$ageDays} day(s).",
                    '/approvals',
                    ['approval_id' => $a->id, 'age_days' => $ageDays],
                    "approval:{$a->id}:approver",
                    'aging',
                    $todayStr,
                    'Follow-up',
                    self::APPROVAL_AGING_DAYS,
                );
            } else {
                $client = Client::find($a->client_id);
                foreach (collect($client?->portalUserIds() ?? [])->all() as $uid) {
                    $this->remind(
                        (int) $uid,
                        'approval',
                        $title,
                        "An approval \"{$a->title}\" has been awaiting your response for {$ageDays} day(s).",
                        '/approvals',
                        ['approval_id' => $a->id, 'age_days' => $ageDays],
                        "approval:{$a->id}:client:{$uid}",
                        'aging',
                        $todayStr,
                        'Follow-up',
                        self::APPROVAL_AGING_DAYS,
                    );
                }
            }

            // Let the requester know it's still hanging.
            $this->remind(
                (int) $a->requester_id,
                'approval',
                "Awaiting response ({$ageDays}d): {$a->title}",
                "Your approval request \"{$a->title}\" is still pending after {$ageDays} day(s).",
                '/approvals',
                ['approval_id' => $a->id, 'age_days' => $ageDays],
                "approval:{$a->id}:requester",
                'aging',
                $todayStr,
                'Follow-up',
                self::APPROVAL_AGING_DAYS,
            );
        }
    }
}
