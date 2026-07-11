<?php

namespace App\Console\Commands;

use App\Models\Approval;
use App\Models\Client;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\TrackerRow;
use App\Support\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SendDeadlineRemindersCommand extends Command
{
    protected $signature = 'reminders:send-deadlines';
    protected $description = 'Notify assignees of upcoming/overdue tracker, task and project deadlines, and aging pending approvals. Also materialises them as reminders.';

    // Days-ahead thresholds for "upcoming" alerts.
    private const THRESHOLDS = [1, 3, 7];
    // Days a client/colleague approval may sit Pending before it is chased.
    private const APPROVAL_AGING_DAYS = 3;

    private int $sent = 0;

    public function handle(): int
    {
        $today = Carbon::today();

        $this->scanTracker($today);
        $this->scanTasks($today);
        $this->scanProjects($today);
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
        }
    }

    /* ───────────────────────── Projects (hard legal deadlines) ───────────────────────── */

    private function scanProjects(Carbon $today): void
    {
        $done = ['Completed', 'Archived'];
        $team = ['assigned_partner_id', 'assigned_manager_id', 'patent_engineer_id'];

        foreach (self::THRESHOLDS as $days) {
            $targetDate = $today->copy()->addDays($days)->toDateString();
            $projects = Project::whereNotNull('hard_deadline')
                ->whereDate('hard_deadline', $targetDate)
                ->whereNotIn('status', $done)
                ->get();

            foreach ($projects as $project) {
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
