<?php

namespace App\Services;

use App\Models\ComplianceItem;
use App\Models\DocketDeadline;
use App\Models\Project;
use App\Models\RenewalSchedule;
use Illuminate\Support\Collection;

class ComplianceSyncService
{
    /** @return array{created:int,updated:int,resolved:int,total:int} */
    public function sync(): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'resolved' => 0, 'total' => 0];
        $activeKeys = collect();

        Project::query()
            ->with('client:id,client_code,legal_name,company_name')
            ->whereNotNull('hard_deadline')
            ->whereNotIn('status', ['Completed', 'Cancelled', 'Archived'])
            ->chunkById(200, function (Collection $projects) use (&$counts, $activeKeys): void {
                foreach ($projects as $project) {
                    $key = "project:{$project->id}:hard_deadline";
                    $activeKeys->push($key);
                    $this->upsert($key, [
                        'client_id' => $project->client_id,
                        'project_id' => $project->id,
                        'patent_application_id' => $project->patent_application_id,
                        'source_type' => 'project_hard_deadline',
                        'source_metadata' => ['project_code' => $project->project_code, 'application_number' => $project->application_number],
                        'matter' => $this->matterName($project),
                        'type' => $this->matterType($project->project_type),
                        'jurisdiction' => $project->patent_office_code ?: 'Unspecified',
                        'deadline' => $project->hard_deadline,
                        'action_required' => 'Complete the matter hard-deadline action',
                    ], $counts);
                }
            });

        DocketDeadline::query()
            ->with(['project.client', 'application.client'])
            ->whereNotIn('status', ['Completed', 'Cancelled', 'Resolved'])
            ->where(function ($query): void { $query->whereNotNull('extended_due_date')->orWhereNotNull('due_date'); })
            ->whereHas('project')
            ->chunkById(200, function (Collection $deadlines) use (&$counts, $activeKeys): void {
                foreach ($deadlines as $deadline) {
                    $project = $deadline->project;
                    $key = "docket_deadline:{$deadline->id}";
                    $activeKeys->push($key);
                    $this->upsert($key, [
                        'client_id' => $project->client_id ?: $deadline->application?->client_id,
                        'project_id' => $project->id,
                        'patent_application_id' => $deadline->patent_application_id,
                        'source_type' => 'docket_deadline',
                        'source_metadata' => ['rule_code' => $deadline->rule_code, 'legal_basis' => $deadline->legal_basis],
                        'matter' => $this->matterName($project),
                        'type' => $this->matterType($project->project_type),
                        'jurisdiction' => $project->patent_office_code ?: ($deadline->application?->jurisdiction ?: 'Unspecified'),
                        'deadline' => $deadline->extended_due_date ?: $deadline->due_date,
                        'action_required' => $deadline->title,
                    ], $counts);
                }
            });

        RenewalSchedule::query()
            ->with(['application.client', 'application.projects'])
            ->whereNotIn('status', ['Paid', 'Completed', 'Cancelled', 'Resolved'])
            ->whereNotNull('due_date')
            ->whereHas('application')
            ->chunkById(200, function (Collection $renewals) use (&$counts, $activeKeys): void {
                foreach ($renewals as $renewal) {
                    $application = $renewal->application;
                    $project = $application->projects->first();
                    $key = "renewal_schedule:{$renewal->id}";
                    $activeKeys->push($key);
                    $this->upsert($key, [
                        'client_id' => $application->client_id,
                        'project_id' => $project?->id,
                        'patent_application_id' => $application->id,
                        'source_type' => 'renewal_schedule',
                        'source_metadata' => ['renewal_year' => $renewal->renewal_year, 'application_number' => $application->application_number],
                        'matter' => $project ? $this->matterName($project) : ($application->application_number ?: $application->title),
                        'type' => 'Patent',
                        'jurisdiction' => $application->jurisdiction ?: 'Unspecified',
                        'deadline' => $renewal->due_date,
                        'action_required' => "Pay patent renewal fee for year {$renewal->renewal_year}",
                    ], $counts);
                }
            });

        $stale = ComplianceItem::query()
            ->where('source_type', '!=', 'manual')
            ->where('status', '!=', 'Resolved')
            ->when($activeKeys->isNotEmpty(), fn ($query) => $query->whereNotIn('source_key', $activeKeys->all()))
            ->update(['status' => 'Resolved', 'resolved_at' => now()]);
        $counts['resolved'] = $stale;
        $counts['total'] = $activeKeys->count();

        return $counts;
    }

    private function upsert(string $key, array $attributes, array &$counts): void
    {
        $item = ComplianceItem::firstOrNew(['source_key' => $key]);
        $isNew = ! $item->exists;
        $item->fill($attributes);
        if ($isNew) {
            $item->status = 'Open';
            $item->resolved_at = null;
        }
        $item->save();
        $counts[$isNew ? 'created' : 'updated']++;
    }

    private function matterName(Project $project): string
    {
        $reference = $project->docket_number ?: $project->project_code;
        $title = $project->invention_title ?: $project->project_name;
        return trim($reference . ($title ? " — {$title}" : ''));
    }

    private function matterType(?string $type): string
    {
        $value = strtolower((string) $type);
        return match (true) {
            str_contains($value, 'trade') => 'Trademark',
            str_contains($value, 'copyright') => 'Copyright',
            str_contains($value, 'design') => 'Design',
            default => 'Patent',
        };
    }
}
