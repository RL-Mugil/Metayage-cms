<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\DocketDeadline;
use App\Services\ReminderThresholdResolver;
use App\Support\Notifier;
use Illuminate\Console\Command;

/**
 * Escalating reminders for open statutory deadlines: base 60 / 30 / 7 / 1 / 0
 * days before due (plus any per-client extra thresholds — e.g. Niramai's
 * extra 30-day-before renewal reminder — via ReminderThresholdResolver),
 * plus a red alert for anything overdue. Runs daily via scheduler.
 */
class NotifyDocketDeadlinesCommand extends Command
{
    protected $signature = 'docket:notify-deadlines';
    protected $description = 'Notify responsible users of upcoming statutory docket deadlines';

    private const BASE_THRESHOLDS = [60, 30, 7, 1, 0];

    public function handle(): int
    {
        $sent = 0;
        $resolver = app(ReminderThresholdResolver::class);
        $clientsById = Client::query()->get(['id', 'reminder_cadence_override'])->keyBy('id');

        // Open deadlines within the widest possible window — client overrides
        // are capped at 365 days (StoreClientRequest/UpdateClientRequest), so
        // that's the ceiling; each deadline is then matched against its own
        // client's resolved threshold set, since thresholds are no longer
        // uniform across all clients.
        $deadlines = DocketDeadline::with(['project:id,docket_number,project_name,client_id,assigned_manager_id,secondary_manager_id,patent_engineer_id'])
            ->where('status', 'Open')
            ->whereDate('due_date', '<=', now()->addDays(365)->toDateString())
            ->whereDate('due_date', '>=', now()->toDateString())
            ->get();

        foreach ($deadlines as $d) {
            $client = $d->project?->client_id ? $clientsById->get($d->project->client_id) : null;
            $thresholds = $resolver->thresholdsFor(self::BASE_THRESHOLDS, $client);
            $days = (int) now()->startOfDay()->diffInDays($d->due_date->copy()->startOfDay());

            if (! in_array($days, $thresholds, true)) {
                continue;
            }

            $recipients = collect([
                $d->project?->assigned_manager_id,
                $d->project?->secondary_manager_id,
                $d->project?->patent_engineer_id,
            ])->filter()->unique();

            if ($recipients->isEmpty()) {
                continue;
            }

            $label = $days === 0 ? 'DUE TODAY' : "due in {$days} day" . ($days > 1 ? 's' : '');
            Notifier::push(
                $recipients,
                'docket_deadline',
                "Statutory deadline {$label}: {$d->title}",
                trim(($d->project?->docket_number ? "[{$d->project->docket_number}] " : '')
                    . ($d->legal_basis ? "({$d->legal_basis}) " : '')
                    . "Due {$d->due_date->format('d-m-Y')}"),
                $d->project_id ? "/projects/{$d->project_id}" : null,
                ['deadline_id' => $d->id, 'days_remaining' => $days]
            );
            $sent++;
        }

        // Overdue — daily red alert until completed or waived
        $overdue = DocketDeadline::with(['project:id,docket_number,project_name,assigned_manager_id,secondary_manager_id,patent_engineer_id'])
            ->where('status', 'Open')
            ->whereDate('due_date', '<', now()->toDateString())
            ->get();

        foreach ($overdue as $d) {
            $recipients = collect([
                $d->project?->assigned_manager_id,
                $d->project?->secondary_manager_id,
                $d->project?->patent_engineer_id,
            ])->filter()->unique();

            if ($recipients->isEmpty()) {
                continue;
            }

            $daysOver = (int) $d->due_date->diffInDays(now());
            Notifier::push(
                $recipients,
                'docket_deadline_overdue',
                "OVERDUE statutory deadline ({$daysOver}d): {$d->title}",
                trim(($d->project?->docket_number ? "[{$d->project->docket_number}] " : '')
                    . ($d->legal_basis ? "({$d->legal_basis}) " : '')
                    . "Was due {$d->due_date->format('d-m-Y')}"
                    . ($d->extended_due_date ? " — outer limit {$d->extended_due_date->format('d-m-Y')}" : '')),
                $d->project_id ? "/projects/{$d->project_id}" : null,
                ['deadline_id' => $d->id, 'days_overdue' => $daysOver]
            );
            $sent++;
        }

        $this->info("Docket deadline notifications sent: {$sent}");
        return self::SUCCESS;
    }
}
