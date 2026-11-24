<?php

namespace App\Services;

use App\Models\Project;
use App\Models\RenewalSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Renewal fees coming up: for each granted application, its next unpaid renewal / annuity
 * (India years 3-20) or US maintenance fee (3.5 / 7.5 / 11.5 years), from renewal_schedules.
 * Overdue ones come first (they have the earliest dates), then the soonest.
 */
class RenewalOutlook
{
    /**
     * @param  Builder|\Illuminate\Database\Query\Builder  $applicationIds  a query selecting patent_application_id (the scope)
     * @return Collection<int, array<string, mixed>>
     */
    public static function nextDue($applicationIds, int $limit = 200): Collection
    {
        $rows = RenewalSchedule::query()
            ->where('status', 'Unpaid')
            ->whereIn('patent_application_id', $applicationIds)
            ->whereRaw('due_date = (SELECT MIN(r2.due_date) FROM renewal_schedules r2 WHERE r2.patent_application_id = renewal_schedules.patent_application_id AND r2.status = ?)', ['Unpaid'])
            ->with(['application:id,application_number,jurisdiction,client_id', 'application.client:id,company_name,legal_name,client_code',
                'application.projects:id,patent_application_id,docket_number,project_name,patent_office_code,service_code,client_id'])
            ->orderBy('due_date')->orderBy('id')
            ->limit($limit)
            ->get();

        $today = now()->startOfDay();

        return $rows->unique('patent_application_id')->map(function (RenewalSchedule $row) use ($today) {
            $projects = $row->application?->projects ?? collect();
            // The renewal / maintenance engagement when there is one, else the case itself.
            $project = $projects->first(fn (Project $p) => in_array(strtoupper((string) $p->service_code), ['REN', 'RNF', 'MFE'], true))
                ?? $projects->first();
            $days = (int) $today->diffInDays($row->due_date, false);

            return [
                'id' => $project?->id,
                'renewal_id' => $row->id,
                'docket_number' => $project?->docket_number ?? $row->application?->application_number,
                'project_name' => $project?->project_name,
                'patent_office_code' => $project?->patent_office_code ?? $row->application?->jurisdiction,
                'application_number' => $row->application?->application_number,
                'client' => $row->application?->client?->only(['id', 'company_name', 'legal_name', 'client_code']),
                'label' => $row->display_label,
                'renewal_year' => $row->renewal_year,
                'due_date' => $row->due_date?->toDateString(),
                'hard_deadline' => $row->due_date?->toDateString(),   // older clients of this payload
                'days' => $days,                                       // negative = overdue
                'window_opens_on' => $row->window_opens_on?->toDateString(),
                'grace_ends_on' => $row->grace_ends_on?->toDateString(),
            ];
        })->values();
    }
}
