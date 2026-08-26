<?php

namespace App\Services;

use App\Models\PatentApplication;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Keeps `application_number` in sync across all engagements that are really the
 * same real-world patent application.
 *
 * The matching key is the docket prefix — client_code + invention_number +
 * patent_office_code — i.e. the docket number with its trailing 3-char
 * service_code stripped off. Two engagements sharing that prefix (e.g.
 * `397M001INFER` and `397M001INCPT`) differ only in service code and are the
 * same application; a different jurisdiction (`397M001US...`) is never part of
 * the same group, by construction, because the office code is part of the key.
 *
 * `PatentApplication.application_number` is the authoritative value; each
 * sibling `Project.application_number` is kept as a synced, denormalized copy
 * (most of the UI reads it directly off Project, so this avoids an app-wide
 * read-site migration for now).
 */
class ApplicationNumberSyncService
{
    /**
     * Ensure every engagement sharing $project's docket prefix (same client,
     * same invention, same patent office) points at one shared PatentApplication
     * carrying $applicationNumber, and copy the number onto each sibling Project.
     *
     * @return Collection<int, Project> siblings (excluding $project itself) that were updated.
     */
    public function backfill(Project $project, string $applicationNumber): Collection
    {
        $applicationNumber = trim($applicationNumber);
        if ($applicationNumber === '') {
            return collect();
        }

        $prefix = $this->docketPrefix($project->docket_number);
        if ($prefix === null) {
            // Docket doesn't look canonical (legacy/manual import) — just sync this project's own application, no group.
            if ($project->patent_application_id) {
                PatentApplication::whereKey($project->patent_application_id)
                    ->update(['application_number' => $applicationNumber]);
            }
            return collect();
        }

        $siblings = Project::query()
            ->where('client_id', $project->client_id)
            ->where('docket_number', 'like', $prefix . '%')
            ->get()
            ->keyBy('id');
        $siblings->put($project->id, $project);

        $application = $this->resolveSharedApplication($project, $siblings, $applicationNumber);

        $touched = collect();
        foreach ($siblings as $sibling) {
            $dirty = false;
            if ((int) $sibling->patent_application_id !== $application->id) {
                $sibling->patent_application_id = $application->id;
                $dirty = true;
            }
            if ($sibling->application_number !== $applicationNumber) {
                $sibling->application_number = $applicationNumber;
                $dirty = true;
            }
            if ($dirty) {
                $sibling->saveQuietly();
                if ($sibling->id !== $project->id) {
                    $touched->push($sibling);
                }
            }
            // Cheap no-op when nothing needs retagging; always run so history
            // recorded before this sibling was linked becomes visible too.
            $this->relinkDocketRecords($sibling, $application->id);
        }

        return $touched->values();
    }

    /**
     * When $project gets (re)pointed at $applicationId — whether via
     * ProjectController::createProjectWithCodes()'s creation-time sibling
     * lookup or via backfill() above — any DocketEvent/DocketDeadline rows
     * already recorded against $project but not yet tagged with an
     * application id are invisible to newly-linked siblings, because
     * DocketController::show() unions by project_id OR patent_application_id.
     * This retags them so already-correct history becomes visible
     * immediately. Not a rewrite of business data — the events/deadlines
     * already existed and were already correct; this only fixes their
     * linkage, so it is safe (and expected) to run every time a link happens,
     * not just as a one-off pass.
     */
    public function relinkDocketRecords(Project $project, int $applicationId): void
    {
        \App\Models\DocketEvent::where('project_id', $project->id)
            ->where(fn ($q) => $q->whereNull('patent_application_id')->orWhere('patent_application_id', '!=', $applicationId))
            ->update(['patent_application_id' => $applicationId]);

        \App\Models\DocketDeadline::where('project_id', $project->id)
            ->where(fn ($q) => $q->whereNull('patent_application_id')->orWhere('patent_application_id', '!=', $applicationId))
            ->update(['patent_application_id' => $applicationId]);
    }

    /** Find (or create) the one PatentApplication all $siblings should share. */
    private function resolveSharedApplication(Project $project, Collection $siblings, string $applicationNumber): PatentApplication
    {
        $existingId = $siblings->pluck('patent_application_id')->filter()->first();

        if ($existingId) {
            $application = PatentApplication::find($existingId);
            if ($application) {
                $application->application_number = $applicationNumber;
                $application->save();
                return $application;
            }
        }

        return PatentApplication::create([
            'client_id'          => $project->client_id,
            'invention_family_id' => $project->invention_family_id,
            'application_number' => $applicationNumber,
            'title'              => $project->invention_title ?: $project->project_name,
            'priority_date'      => $project->priority_date,
            'filing_date'        => $project->filing_date,
            'legal_status'       => $this->deriveLegalStatus($project),
            'jurisdiction'       => $project->patent_office_code ?: 'IN',
        ]);
    }

    /**
     * A newly-created PatentApplication (from backfilling a legacy project that
     * already has a real-world outcome) must reflect that outcome, not default
     * to Pending — otherwise the IPO-style status pipeline shows "Filed" for a
     * case that's actually Granted/Refused. Best-effort from what Project
     * already records; a real docket event (see DocketRules) always takes over
     * once one is recorded.
     */
    public function deriveLegalStatus(Project $project): string
    {
        if ($project->patent_granted || $project->status === 'Granted') {
            return 'Granted';
        }
        return match ($project->status) {
            'Refused' => 'Refused',
            'Abandoned' => 'Abandoned',
            default => 'Pending',
        };
    }

    /** client_code+invention_number+patent_office_code — the docket number minus its trailing 3-char service code. */
    public function docketPrefix(?string $docket): ?string
    {
        $docket = strtoupper(trim((string) $docket));
        $suffixLength = DocketNumberService::SERVICE_LENGTH;
        if (strlen($docket) <= $suffixLength) {
            return null;
        }
        return substr($docket, 0, -$suffixLength);
    }
}
