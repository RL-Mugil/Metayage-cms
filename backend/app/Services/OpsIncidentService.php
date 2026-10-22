<?php

namespace App\Services;

use App\Mail\OpsReportMail;
use App\Models\OpsIncident;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The quiet collection point for operational problems. Nothing here sends an email when a problem is recorded:
 * the morning report (OpsDailyReport) lists what is open, and a recovery email goes out only when a problem that was
 * already reported clears up.
 */
class OpsIncidentService
{
    /** Never throws: recording a problem must not cause another one. */
    public function recordException(\Throwable $e): void
    {
        try {
            $d = OpsPlainEnglish::describe($e);

            // The scheduler's "command failed" line repeats an error that was just recorded on its own: skip it then.
            if ($d['wrapper'] && OpsIncident::where('scope', $d['scope'])->where('status', 'open')->where('last_seen_at', '>=', now()->subMinutes(2))->exists()) {
                return;
            }

            $this->record($d['fingerprint'], $d['title'], $d['meaning'], $d['impact'], $d['action'], $d['technical'], $d['quiet'], $d['scope'], 'app');
        } catch (\Throwable $inner) {
            Log::warning('Could not record ops incident: '.$inner->getMessage());
        }
    }

    public function record(string $fingerprint, string $title, string $meaning, string $impact, string $action, ?string $technical, int $quietMinutes = 360, ?string $scope = null, string $source = 'app'): OpsIncident
    {
        $now = now();
        $incident = OpsIncident::where('fingerprint', $fingerprint)->first();

        if ($incident && $incident->status === 'open') {
            $incident->update(['occurrences' => $incident->occurrences + 1, 'last_seen_at' => $now, 'technical' => $technical ? mb_substr($technical, 0, 1000) : $incident->technical]);

            return $incident;
        }

        // First time, or it is back after having recovered: a new episode.
        $values = [
            'source' => $source, 'scope' => $scope, 'title' => mb_substr($title, 0, 250), 'meaning' => $meaning, 'impact' => $impact, 'action' => $action,
            'technical' => $technical ? mb_substr($technical, 0, 1000) : null, 'occurrences' => 1, 'first_seen_at' => $now, 'last_seen_at' => $now,
            'quiet_minutes' => $quietMinutes, 'status' => 'open', 'resolved_at' => null, 'reported_at' => null, 'recovery_mailed_at' => null,
        ];

        return $incident ? tap($incident)->update($values) : OpsIncident::create(['fingerprint' => $fingerprint] + $values);
    }

    /** Resolve one problem by fingerprint, or everything raised by one scheduled command. Returns the incidents that were open. */
    public function resolve(?string $fingerprint = null, ?string $scope = null): Collection
    {
        $query = OpsIncident::where('status', 'open');
        $fingerprint !== null ? $query->where('fingerprint', $fingerprint) : $query->where('scope', $scope);
        $open = $query->get();
        $open->each(fn (OpsIncident $i) => $i->update(['status' => 'resolved', 'resolved_at' => now()]));

        return $open;
    }

    /** A scheduled command just finished successfully: whatever it had been failing with is over. */
    public function commandSucceeded(string $command): void
    {
        try {
            $resolved = $this->resolve(null, $command)->merge($this->resolve('schedule|'.$command));
            $this->mailRecoveries($resolved);
        } catch (\Throwable $e) {
            Log::warning('Could not resolve ops incidents for '.$command.': '.$e->getMessage());
        }
    }

    /** Resolve app problems that have not repeated for their quiet period, then send ONE recovery email for any that had been reported. */
    public function sweep(): int
    {
        $stale = OpsIncident::where('status', 'open')->where('source', 'app')->where('quiet_minutes', '>', 0)->get()
            ->filter(fn (OpsIncident $i) => $i->last_seen_at->lt(now()->subMinutes($i->quiet_minutes)));
        $stale->each(fn (OpsIncident $i) => $i->update(['status' => 'resolved', 'resolved_at' => now()]));
        $this->mailRecoveries($stale);

        return $stale->count();
    }

    /**
     * Immediate "it is working again" email, only for problems that were in a morning report. One email however many
     * recovered together; problems that came and went between reports are simply listed in the next report instead.
     */
    public function mailRecoveries(Collection $incidents): void
    {
        $due = $incidents->filter(fn (OpsIncident $i) => $i->reported_at !== null && $i->recovery_mailed_at === null)->values();
        if ($due->isEmpty()) {
            return;
        }
        $to = app(OpsAlertService::class)->emailRecipients();
        if ($to === []) {
            return;
        }
        try {
            Mail::to($to)->send(OpsReportMail::recovery($due));
            $due->each(fn (OpsIncident $i) => $i->update(['recovery_mailed_at' => now()]));
        } catch (\Throwable $e) {
            Log::warning('Recovery email failed: '.$e->getMessage());
        }
    }

    /** @return Collection<int, OpsIncident> */
    public function open(): Collection
    {
        return OpsIncident::where('status', 'open')->orderBy('first_seen_at')->get();
    }

    /** Resolved in the last day and never mentioned in a report. */
    public function recentlyResolved(): Collection
    {
        return OpsIncident::where('status', 'resolved')->whereNull('recovery_mailed_at')->where('resolved_at', '>=', now()->subHours(26))->orderBy('resolved_at')->get();
    }
}
