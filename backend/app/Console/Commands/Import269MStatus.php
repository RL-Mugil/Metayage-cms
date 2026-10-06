<?php

namespace App\Console\Commands;

use App\Http\Controllers\ProjectController;
use App\Models\Client;
use App\Models\DocketEvent;
use App\Models\PatentApplication;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Support\DocketRules;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Import the 269M status workbook without duplicating portal matters. */
class Import269MStatus extends Command
{
    protected $signature = 'appviewx:import-269m-status {--file=storage/app/imports/269m-status.json} {--dry-run}';
    protected $description = 'Import 269M status workbook records and dated docket events';

    public function handle(): int
    {
        $path = base_path($this->option('file'));
        if (! is_file($path)) {
            $this->error("Import file not found: {$path}");
            return self::FAILURE;
        }
        $records = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $client = Client::where('client_code', '269M')->firstOrFail();
        $dry = (bool) $this->option('dry-run');
        $created = $updated = $events = 0;
        foreach ($records as $record) {
            $existing = $this->findExisting($client->id, $record);
            if ($dry) {
                $this->line(($existing ? 'UPDATE ' : 'CREATE ').$record['docket'].' | '.($record['title'] ?? ''));
                continue;
            }
            DB::transaction(function () use ($client, $record, &$created, &$updated, &$events): void {
                $project = $this->findExisting($client->id, $record);
                if ($project) {
                    if ($project->trashed()) $project->restore();
                    $project->update($this->payload($client->id, $record, false, $project));
                    $updated++;
                } else {
                    $project = app(ProjectController::class)->createFromImport($this->payload($client->id, $record, true));
                    $created++;
                }
                $project->update(['patent_granted' => ($record['status'] ?? '') === 'Granted']);
                $this->syncApplication($project, $record);
                $this->mapStages($project, $record);
                $events += $this->recordDatedEvent($project, $record);
            }, 3);
        }
        $this->info("269M import complete: {$created} created, {$updated} updated, {$events} dated docket events, ".count($records).' rows processed.');
        return self::SUCCESS;
    }

    private function findExisting(int $clientId, array $r): ?Project
    {
        $q = Project::withTrashed()->where('client_id', $clientId);
        $project = (clone $q)->where(function ($x) use ($r) {
            $x->where('docket_number', $r['docket'])->orWhere('project_code', $r['docket'])->orWhere('original_docket', $r['raw']);
        })->first();
        if ($project || empty($r['app'])) return $project;
        return (clone $q)->where('application_number', $r['app'])->first();
    }

    private function payload(int $clientId, array $r, bool $new, ?Project $project = null): array
    {
        $kind = ($r['source'] ?? '') === 'DSN' ? 'Design' : 'Patent';
        $status = $r['status'] ?: 'In Progress';
        $notes = "Source workbook: {$r['source']}.";
        if (! empty($r['source_status'])) $notes .= " Status: {$r['source_status']}.";
        if (! empty($r['next'])) $notes .= " Next action: {$r['next']}.";
        if (! empty($r['inventors'])) $notes .= " Inventors: {$r['inventors']}.";
        $data = [
            'client_id' => $clientId,
            'project_name' => $r['title'] ?: ('Matter '.$r['docket']),
            'invention_title' => $r['title'] ?: ('Matter '.$r['docket']),
            'project_type' => $kind,
            'case_type' => $status === 'Granted' ? 'Granted Patent' : 'Patent Prosecution',
            'application_number' => $r['app'] ?: null,
            'patent_office_code' => $r['office'] ?: 'IN',
            'service_code' => $r['service'] ?: 'NPV',
            'status' => $status,
            'original_docket' => $r['raw'],
            'notes' => $notes,
        ];
        if (! empty($r['filing']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['filing'])) {
            $data['filing_date'] = $r['filing'];
            $data['start_date'] = $r['filing'];
        }
        // A status-sheet row without an application/date must never erase a
        // value supplied by another sheet or already present in the portal.
        if (! $new && empty($r['app'])) unset($data['application_number']);
        if ($new) {
            $data['record_mode'] = 'existing';
            $data['project_code'] = $r['docket'];
            $data['docket_number'] = $r['docket'];
        }
        return $data;
    }

    private function syncApplication(Project $project, array $r): void
    {
        if (! $project->patent_application_id) return;
        $updates = ['title' => $r['title'] ?: $project->invention_title];
        if (! empty($r['app'])) $updates['application_number'] = $r['app'];
        if (! empty($r['source_status']) && ($r['status'] ?? '') === 'Granted') $updates['legal_status'] = 'Granted';
        PatentApplication::whereKey($project->patent_application_id)->update($updates);
    }

    private function mapStages(Project $project, array $r): void
    {
        $stages = $project->stages()->orderBy('sequence_order')->get();
        if (! $stages->count()) return;
        if (($r['status'] ?? '') === 'Granted') {
            $stages->each(fn ($stage) => $stage->update(['status' => 'Completed']));
            return;
        }
        // Stage names are never invented here. Score the existing, approved
        // template options against the workbook's status and next-action text.
        // The selected value is therefore always one of the portal dropdown
        // options for this project.
        $text = strtolower(implode(' ', array_filter([
            $r['source'] ?? '', $r['source_status'] ?? '', $r['next'] ?? '', $r['service'] ?? '',
        ])));
        $tokens = array_values(array_filter(preg_split('/[^a-z0-9]+/', $text) ?: [], fn ($token) => strlen($token) >= 4));
        $current = $stages->sortByDesc(function ($stage) use ($tokens) {
            $nameTokens = array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower($stage->stage_name)) ?: [], fn ($token) => strlen($token) >= 4));
            $score = 0;
            foreach ($tokens as $token) foreach ($nameTokens as $nameToken) {
                if ($token === $nameToken) $score += 4;
                elseif (strlen($token) >= 5 && strlen($nameToken) >= 5 && str_starts_with($token, substr($nameToken, 0, 5))) $score += 2;
            }
            return $score * 1000 - (int) $stage->sequence_order;
        })->first();
        $bestScore = 0;
        if ($current) {
            $nameTokens = array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower($current->stage_name)) ?: [], fn ($token) => strlen($token) >= 4));
            foreach ($tokens as $token) foreach ($nameTokens as $nameToken) if ($token === $nameToken || (strlen($token) >= 5 && strlen($nameToken) >= 5 && str_starts_with($token, substr($nameToken, 0, 5)))) $bestScore++;
        }
        if ($bestScore === 0) $current = $stages->firstWhere('status', 'In Progress') ?: $stages->first();
        if (! $current) $current = $stages->last();
        if (! $current) return;
        $stages->each(function ($stage) use ($current): void {
            $stage->update(['status' => $stage->sequence_order < $current->sequence_order ? 'Completed' : ($stage->id === $current->id ? 'In Progress' : 'Pending')]);
        });
    }

    private function recordDatedEvent(Project $project, array $r): int
    {
        $date = $r['source_date'] ?? '';
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) return 0;
        $type = $this->eventType($r);
        if (! $type || ! isset(DocketRules::EVENT_TYPES[$type])) return 0;
        if (DocketEvent::where('project_id', $project->id)->where('event_type', $type)->whereDate('event_date', $date)->exists()) return 0;
        DocketRules::recordEvent($type, Carbon::parse($date), $project->id, $project->patent_application_id, 'Imported from 269M status workbook. '.($r['source_status'] ?? $r['next'] ?? ''), null);
        return 1;
    }

    private function eventType(array $r): ?string
    {
        $source = $r['source'] ?? '';
        if ($source === 'Dropped' || ($r['status'] ?? '') === 'Refused') return 'refused';
        if ($source === 'Hearing') return 'hearing_notice';
        if ($source === 'FER') return 'fer_received';
        if ($source === 'In Provisional filed cases') return 'provisional_filed';
        if ($source === 'IN Complete filed cases') return 'application_filed';
        if ($source === 'IN Granted' || $source === 'US Granted') return 'granted';
        if (str_contains($source, 'Final rejection')) return 'us_final_office_action';
        if (str_contains($source, 'Non final')) return 'us_nonfinal_office_action';
        if ($source === 'Other countries' && ($r['office'] ?? '') === 'WO') return 'pct_filed';
        return null;
    }
}
