<?php

namespace App\Services;

use App\Models\JurisdictionLifecycleTemplate;
use App\Models\Project;
use App\Models\ProjectStage;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class JurisdictionLifecycleService
{
    public function resolve(string $jurisdiction, string $serviceCode, ?CarbonInterface $asOf = null): JurisdictionLifecycleTemplate
    {
        $date = ($asOf ?? now())->toDateString();

        $template = JurisdictionLifecycleTemplate::query()
            ->with('stages')
            ->where('jurisdiction', strtoupper($jurisdiction))
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->whereIn('service_code', [strtoupper($serviceCode), '*'])
            ->orderByRaw("CASE WHEN service_code = ? THEN 0 ELSE 1 END", [strtoupper($serviceCode)])
            ->orderByDesc('effective_from')
            ->first();

        if (! $template) {
            throw ValidationException::withMessages([
                'patent_office_code' => 'No approved lifecycle template is available for this jurisdiction and service.',
            ]);
        }

        return $template;
    }

    public function apply(Project $project, JurisdictionLifecycleTemplate $template): void
    {
        $project->update([
            'jurisdiction_lifecycle_template_id' => $template->id,
            'lifecycle_template_version' => $template->version,
        ]);

        foreach ($template->stages as $stage) {
            ProjectStage::create([
                'project_id' => $project->id,
                'stage_name' => $stage->stage_name,
                'status' => $stage->sequence_order === 0 ? 'In Progress' : 'Pending',
                'sequence_order' => $stage->sequence_order,
                'duration_days' => $stage->target_duration_days ?? 0,
                'gate_criteria' => $stage->gate_criteria,
            ]);
        }
    }
}
