<?php

namespace App\Services;

use App\Models\DeadlineRuleDefinition;
use App\Models\DocketDeadline;
use App\Models\DocketEvent;
use App\Models\PatentApplication;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DeadlineRuleEngine
{
    public function simulateRule(DeadlineRuleDefinition $rule, Carbon $anchor): array
    {
        $due = $this->offset($anchor, $rule->offset_unit, $rule->offset_value);
        $outer = $rule->outer_offset_value === null ? null : $this->offset($anchor, $rule->offset_unit, $rule->outer_offset_value);
        return ['rule_code' => $rule->rule_code, 'version' => $rule->version, 'anchor_date' => $anchor->toDateString(),
            'statutory_due_date' => $due->toDateString(), 'outer_limit_date' => $outer?->toDateString(),
            'trace' => ['unit' => $rule->offset_unit, 'offset' => $rule->offset_value, 'outer_offset' => $rule->outer_offset_value]];
    }

    public function activeRules(string $jurisdiction, string $eventType, Carbon $eventDate): Collection
    {
        return DeadlineRuleDefinition::query()
            ->where('jurisdiction', strtoupper($jurisdiction))
            ->where('event_type', $eventType)
            ->where('status', 'Approved')
            ->whereNotNull('approved_by')->whereNotNull('approved_at')
            ->whereDate('effective_from', '<=', $eventDate)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $eventDate))
            ->orderBy('rule_code')->get();
    }

    public function generate(DocketEvent $event, ?Project $project, ?PatentApplication $application): Collection
    {
        $jurisdiction = strtoupper((string) ($project?->patent_office_code ?: $application?->jurisdiction ?: 'IN'));
        $eventDate = Carbon::parse($event->event_date);

        return $this->activeRules($jurisdiction, $event->event_type, $eventDate)->map(function (DeadlineRuleDefinition $rule) use ($event, $project, $application, $eventDate, $jurisdiction): DocketDeadline {
            $anchor = $rule->anchor_field === 'priority_date' && $application?->priority_date
                ? Carbon::parse($application->priority_date) : $eventDate->copy();
            $due = $this->offset($anchor, $rule->offset_unit, $rule->offset_value);
            $outer = $rule->outer_offset_value === null ? null
                : $this->offset($anchor, $rule->offset_unit, $rule->outer_offset_value);

            return DocketDeadline::create([
                'docket_event_id' => $event->id,
                'project_id' => $project?->id,
                'ip_record_id' => $project?->ip_record_id,
                'patent_application_id' => $application?->id,
                'deadline_rule_definition_id' => $rule->id,
                'title' => $rule->title,
                'legal_basis' => $rule->legal_basis,
                'source_type' => 'System Rule',
                'rule_code' => $rule->rule_code,
                'rule_version' => $rule->version,
                'risk_level' => 'Critical',
                'calculation_trace' => [
                    'jurisdiction' => $jurisdiction,
                    'right_type' => $rule->right_type,
                    'trigger_event' => $event->event_type,
                    'trigger_date' => $eventDate->toDateString(),
                    'anchor_field' => $rule->anchor_field,
                    'anchor_date' => $anchor->toDateString(),
                    'offset_unit' => $rule->offset_unit,
                    'offset_value' => $rule->offset_value,
                    'calculated_due_date' => $due->toDateString(),
                    'calculated_outer_limit' => $outer?->toDateString(),
                    'approved_rule_id' => $rule->id,
                ],
                'review_status' => 'Unreviewed',
                'due_date' => $due,
                'statutory_due_date' => $due,
                'extended_due_date' => $outer,
                'status' => 'Open',
            ]);
        });
    }

    private function offset(Carbon $anchor, string $unit, int $value): Carbon
    {
        return match ($unit) {
            'days' => $anchor->copy()->addDays($value),
            'years' => $anchor->copy()->addYears($value),
            default => $anchor->copy()->addMonthsNoOverflow($value),
        };
    }
}
