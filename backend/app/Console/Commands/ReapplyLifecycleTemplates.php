<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\LifecycleReapplyService;
use Illuminate\Console\Command;

/**
 * Moves existing cases onto the lifecycle template that now fits their office and service
 * (after templates are added or changed). --dry-run lists what would change.
 */
class ReapplyLifecycleTemplates extends Command
{
    protected $signature = 'lifecycle:reapply {--office= : Patent office code, e.g. US} {--service= : Service code} {--dry-run : List the cases without changing them}';

    protected $description = 'Re-apply the matching lifecycle template to existing cases (keeps a snapshot of the old stages)';

    public function handle(LifecycleReapplyService $service): int
    {
        $query = Project::query()->with('lifecycleTemplate')->orderBy('id');
        if ($office = $this->option('office')) {
            $query->whereRaw('UPPER(patent_office_code) = ?', [strtoupper($office)]);
        }
        if ($code = $this->option('service')) {
            $query->whereRaw('UPPER(service_code) = ?', [strtoupper($code)]);
        }
        $dry = (bool) $this->option('dry-run');
        $version = 'reapply-'.now()->format('YmdHis');
        $rows = [];

        $query->chunkById(100, function ($projects) use ($service, $dry, $version, &$rows) {
            foreach ($projects as $project) {
                $plan = $dry ? $service->plan($project) : $service->reapply($project, $version);
                if ($plan) {
                    $rows[] = [$project->docket_number ?? $project->project_code, $project->service_code, $plan['from'] ?? '-', $plan['to'],
                        "{$plan['old_completed']} → {$plan['new_completed']}"];
                }
            }
        });

        $this->table(['Case', 'Service', 'From', 'To', 'Completed stages'], $rows);
        $this->info(($dry ? 'Would move ' : 'Moved ').count($rows).' case(s).');

        return self::SUCCESS;
    }
}
