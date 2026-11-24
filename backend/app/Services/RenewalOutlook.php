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
    /** @var array<string, \Illuminate\Support\Collection> fee cards per jurisdiction, per request */
    private static array $cards = [];

    /**
     * Estimated fee (government + professional, standard tier) for one renewal year, from the
     * fee rate cards: India REN/RNF bands, US MFE at 3.5 / 7.5 / 11.5 years. Null when no card.
     *
     * @return array{amount: float, currency: string}|null
     */
    public static function estimate(string $jurisdiction, int $year, bool $usMaintenance = false): ?array
    {
        $jurisdiction = strtoupper($jurisdiction);
        $codes = $usMaintenance ? ['MFE'] : ['REN', 'RNF'];
        $key = $jurisdiction.'|'.implode(',', $codes);
        self::$cards[$key] ??= \App\Models\FeeRateCard::where('jurisdiction', $jurisdiction)->whereIn('service_code', $codes)
            ->where('is_active', true)->where(fn ($q) => $q->where('entity_tier', 'standard')->orWhereNull('entity_tier'))->get();
        $band = $usMaintenance ? $year - 0.5 : $year;
        $card = self::$cards[$key]->first(fn ($r) => $band >= (float) $r->year_from && $band <= (float) $r->year_to);
        if (! $card || ($card->govt_fee_amount === null && $card->professional_fee_amount === null)) {
            return null;
        }

        return ['amount' => round((float) $card->govt_fee_amount + (float) $card->professional_fee_amount, 2),
            'currency' => $card->govt_fee_currency ?: $card->professional_fee_currency ?: 'INR'];
    }

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
                'estimate' => self::estimate((string) ($project?->patent_office_code ?? $row->application?->jurisdiction ?? 'IN'), (int) $row->renewal_year, $row->isUsMaintenance()),
                'window_opens_on' => $row->window_opens_on?->toDateString(),
                'grace_ends_on' => $row->grace_ends_on?->toDateString(),
            ];
        })->values();
    }
}
