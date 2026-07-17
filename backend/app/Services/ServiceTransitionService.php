<?php

namespace App\Services;

use App\Models\DocketEvent;
use App\Models\Project;
use App\Models\ServiceTransitionRule;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ServiceTransitionService
{
    public function available(Project $project): Collection
    {
        $rules = ServiceTransitionRule::query()
            ->where('jurisdiction', strtoupper((string) $project->patent_office_code))
            ->where('from_service_code', strtoupper((string) $project->service_code))
            ->where('is_active', true)->orderBy('to_service_code')->get();

        return $rules->map(fn (ServiceTransitionRule $rule) => array_merge($rule->toArray(), [
            'eligible' => $this->eligible($project, $rule),
            'blocker' => $this->blocker($project, $rule),
        ]));
    }

    public function assertAllowed(Project $project, string $office, string $toService): void
    {
        if (strtoupper((string) $project->patent_office_code) !== strtoupper($office)) {
            return;
        }
        $rules = ServiceTransitionRule::query()
            ->where('jurisdiction', strtoupper($office))
            ->where('from_service_code', strtoupper((string) $project->service_code))
            ->where('is_active', true);

        // Jurisdictions and service families not yet configured retain the legacy path.
        if (! $rules->exists()) {
            return;
        }

        $rule = $rules->where('to_service_code', strtoupper($toService))->first();
        if (! $rule || ! $this->eligible($project, $rule)) {
            throw ValidationException::withMessages(['service_code' => $rule ? $this->blocker($project, $rule) : 'This successor service is not permitted from the current engagement.']);
        }
    }

    private function eligible(Project $project, ServiceTransitionRule $rule): bool
    {
        if ($rule->required_application_status && $project->patentApplication?->legal_status !== $rule->required_application_status) {
            return false;
        }
        return ! $rule->required_event_type || DocketEvent::query()
            ->where('patent_application_id', $project->patent_application_id)
            ->where('event_type', $rule->required_event_type)->exists();
    }

    private function blocker(Project $project, ServiceTransitionRule $rule): ?string
    {
        if ($rule->required_application_status && $project->patentApplication?->legal_status !== $rule->required_application_status) {
            return "Requires application legal status {$rule->required_application_status}.";
        }
        if ($rule->required_event_type && ! DocketEvent::query()->where('patent_application_id', $project->patent_application_id)->where('event_type', $rule->required_event_type)->exists()) {
            return "Record the {$rule->required_event_type} office event before creating this engagement.";
        }
        return null;
    }
}
