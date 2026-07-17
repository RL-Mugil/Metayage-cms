<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InventionFamily;
use App\Models\PatentApplication;
use App\Models\Project;
use App\Models\ProjectElevation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventionFamilyService
{
    public function attach(Project $project): InventionFamily
    {
        if (! preg_match('/^\d{3}$/', (string) $project->invention_number)) {
            throw ValidationException::withMessages(['invention_number' => 'A canonical invention number is required.']);
        }

        $family = InventionFamily::query()->firstOrCreate(
            [
                'firm_id' => $project->firm_id,
                'client_id' => $project->client_id,
                'invention_number' => $project->invention_number,
            ],
            [
                'title' => $project->invention_title ?: $project->project_name,
                'earliest_priority_date' => $project->priority_date,
                'status' => 'Active',
            ],
        );

        if ($project->priority_date && (! $family->earliest_priority_date || $project->priority_date->lt($family->earliest_priority_date))) {
            $family->update(['earliest_priority_date' => $project->priority_date]);
        }

        if ((int) $project->invention_family_id !== (int) $family->id) {
            $project->update(['invention_family_id' => $family->id]);
        }

        return $family;
    }

    public function createBranch(Project $source, array $attributes, User $actor, array $auditContext = []): Project
    {
        return DB::transaction(function () use ($source, $attributes, $actor, $auditContext): Project {
            $source = Project::query()->lockForUpdate()->with('client')->findOrFail($source->id);
            $family = $source->invention_family_id
                ? InventionFamily::query()->lockForUpdate()->findOrFail($source->invention_family_id)
                : $this->attach($source);

            $office = strtoupper($attributes['patent_office_code']);
            $service = strtoupper($attributes['service_code']);
            $docket = app(DocketNumberService::class)->compose(
                $source->client->client_code,
                $family->invention_number,
                $office,
                $service,
            );

            if (Project::withTrashed()->where(fn ($query) => $query->where('project_code', $docket)->orWhere('docket_number', $docket))->exists()) {
                throw ValidationException::withMessages(['service_code' => "Engagement {$docket} already exists."]);
            }

            $application = PatentApplication::query()
                ->where('invention_family_id', $family->id)
                ->where('jurisdiction', $office)
                ->first();

            if (! $application) {
                $application = PatentApplication::create([
                    'firm_id' => $source->firm_id,
                    'invention_family_id' => $family->id,
                    'client_id' => $source->client_id,
                    'title' => $family->title,
                    'priority_date' => $family->earliest_priority_date,
                    'filing_date' => $attributes['filing_date'] ?? null,
                    'application_number' => $attributes['application_number'] ?? null,
                    'legal_status' => 'Pending',
                    'jurisdiction' => $office,
                ]);
            }

            $successor = $source->replicate([
                'project_code', 'docket_number', 'original_docket', 'patent_office_code', 'service_code',
                'application_number', 'filing_date', 'hard_deadline', 'status', 'patent_application_id',
                'parent_project_id', 'created_at', 'updated_at', 'deleted_at',
            ]);
            $successor->fill([
                'project_code' => $docket,
                'docket_number' => $docket,
                'original_docket' => $docket,
                'invention_family_id' => $family->id,
                'invention_number' => $family->invention_number,
                'parent_project_id' => $source->id,
                'patent_application_id' => $application->id,
                'patent_office_code' => $office,
                'service_code' => $service,
                'application_number' => $application->application_number,
                'filing_date' => $attributes['filing_date'] ?? $application->filing_date,
                'hard_deadline' => $attributes['hard_deadline'] ?? null,
                'status' => 'Open',
                'notes' => $attributes['note'] ?? $source->notes,
                'docket_reviewer_id' => $attributes['docket_reviewer_id'] ?? $source->docket_reviewer_id ?? $source->assigned_manager_id ?? $actor->id,
            ]);
            $successor->save();

            $template = app(JurisdictionLifecycleService::class)->resolve($office, $service);
            app(JurisdictionLifecycleService::class)->apply($successor, $template);

            ProjectElevation::create([
                'project_id' => $successor->id,
                'predecessor_project_id' => $source->id,
                'from_service_code' => strtoupper((string) $source->service_code),
                'to_service_code' => $service,
                'from_docket' => $source->docket_number,
                'to_docket' => $docket,
                'elevated_at' => now(),
                'elevated_by_id' => $actor->id,
                'note' => $attributes['note'] ?? null,
                'is_retroactive_link' => false,
            ]);

            if (($attributes['complete_source'] ?? false) === true) {
                $source->update(['status' => 'Completed']);
                $source->stages()->where('status', '!=', 'Completed')->update(['status' => 'Completed', 'actual_end_at' => now()]);
            }

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'create_family_engagement',
                'subject_type' => 'Project',
                'subject_id' => $successor->id,
                'metadata' => [
                    'family_id' => $family->id,
                    'source_project_id' => $source->id,
                    'source_docket' => $source->docket_number,
                    'new_docket' => $docket,
                    'jurisdiction' => $office,
                    'service_code' => $service,
                    'lifecycle_template_id' => $template->id,
                    'lifecycle_template_version' => $template->version,
                ],
                'ip_address' => $auditContext['ip_address'] ?? null,
                'user_agent' => $auditContext['user_agent'] ?? null,
            ]);

            return $successor->fresh(['client', 'patentApplication', 'inventionFamily', 'stages']);
        });
    }
}
