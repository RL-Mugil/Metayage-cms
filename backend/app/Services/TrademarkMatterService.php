<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Firm;
use App\Models\IpRecord;
use App\Models\JurisdictionLifecycleTemplate;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\TrademarkApplication;
use App\Models\User;
use App\Support\FirmContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Links trademark cases (TM* service codes) to their IpRecord + TrademarkApplication
 * and stores the mark representation (logo) as a case Document.
 */
class TrademarkMatterService
{
    public const MARK_FOLDER = 'Trademarks';
    private const MARK_FOLDER_PATH = 'documents/'.self::MARK_FOLDER.'/';

    public static function isTrademarkCode(?string $serviceCode): bool
    {
        return str_starts_with(strtoupper((string) $serviceCode), 'TM');
    }

    public static function isFilingCode(?string $serviceCode): bool
    {
        return in_array(strtoupper((string) $serviceCode), config('trademark_lifecycle.filing_codes'), true);
    }

    public static function markTypes(): array
    {
        return array_keys(config('trademark_lifecycle.mark_types'));
    }

    public static function requiresImage(?string $markType): bool
    {
        return (bool) (config('trademark_lifecycle.mark_types')[$markType] ?? false);
    }

    /** Next IPR-{year}-{seq} record code. Must run inside a transaction. */
    public function nextRecordCode(int $firmId): string
    {
        Firm::whereKey($firmId)->lockForUpdate()->firstOrFail();
        $year = now()->year;
        $last = IpRecord::withTrashed()->where('record_code', 'like', "IPR-{$year}-%")
            ->orderByDesc('record_code')->lockForUpdate()->value('record_code');
        $sequence = $last ? ((int) substr($last, -5)) + 1 : 1;

        return sprintf('IPR-%d-%05d', $year, $sequence);
    }

    /**
     * Attach a trademark case to its IpRecord. Follow-on engagements (TME, TMOR,
     * TMRN, …) for the same client + invention number share the original filing's
     * record, so the mark and logo are entered once. Must run inside a transaction.
     *
     * @param  array{mark_text?: ?string, mark_type?: ?string, nice_classes?: ?array}  $details
     */
    public function attach(Project $project, array $details, ?int $userId): TrademarkApplication
    {
        $details = array_filter([
            'mark_text' => isset($details['mark_text']) ? trim((string) $details['mark_text']) : null,
            'mark_type' => $details['mark_type'] ?? null,
            'nice_classes' => isset($details['nice_classes'])
                ? array_values(array_unique(array_map('intval', $details['nice_classes'])))
                : null,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        $record = $project->ip_record_id
            ? IpRecord::lockForUpdate()->find($project->ip_record_id)
            : null;
        $record ??= $project->invention_number
            ? IpRecord::where('client_id', $project->client_id)
                ->where('record_type', 'Trademark')
                ->whereHas('projects', fn ($query) => $query
                    ->where('client_id', $project->client_id)
                    ->where('invention_number', $project->invention_number)
                    ->where('id', '!=', $project->id))
                ->lockForUpdate()
                ->first()
            : null;

        if ($record) {
            $application = $record->trademarkApplication()->firstOrCreate([], ['mark_text' => $record->title]);
            if ($details !== []) {
                $application->update($details);
            }
            if (isset($details['mark_text'])) {
                $record->update(['title' => $details['mark_text']]);
            }
            $action = $project->ip_record_id === $record->id ? 'update_trademark' : 'link_trademark';
        } else {
            $firmId = app(FirmContext::class)->idOrSingleActiveFirm();
            // firm_id is filled by BelongsToFirm from the same FirmContext.
            $record = IpRecord::create([
                'client_id' => $project->client_id,
                'record_code' => $this->nextRecordCode($firmId),
                'record_type' => 'Trademark',
                'jurisdiction' => strtoupper($project->patent_office_code ?: 'IN'),
                'title' => $details['mark_text'] ?? $project->project_name,
                'responsible_user_id' => $project->assigned_manager_id,
            ]);
            $application = TrademarkApplication::create($details + [
                'ip_record_id' => $record->id,
                'application_number' => $project->application_number ?: null,
            ]);
            $action = 'create_trademark';
        }

        $project->update(['ip_record_id' => $record->id]);

        AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => 'Project',
            'subject_id' => $project->id,
            'metadata' => ['ip_record_id' => $record->id, 'record_code' => $record->record_code] + $details,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);

        return $application;
    }

    /**
     * Seed a trademark case's stages: the office's exact-service template when one
     * exists, otherwise the config list (never the patent-only '*' wildcard).
     */
    public function seedStages(Project $project): ?JurisdictionLifecycleTemplate
    {
        $service = strtoupper((string) $project->service_code);
        try {
            $template = app(JurisdictionLifecycleService::class)->resolve($project->patent_office_code ?: 'IN', $service);
            if ($template->service_code !== '*') {
                app(JurisdictionLifecycleService::class)->apply($project, $template);

                return $template;
            }
        } catch (ValidationException) {
            // No template for this office — use the config list below.
        }

        $stages = config("trademark_lifecycle.templates.{$service}.1")
            ?? config('trademark_lifecycle.templates.TMF.1');
        foreach ($stages as $index => $stageName) {
            ProjectStage::create([
                'project_id' => $project->id,
                'stage_name' => $stageName,
                'status' => $index === 0 ? 'In Progress' : 'Pending',
                'sequence_order' => $index,
                'duration_days' => 15,
                'due_date' => now()->addDays(($index + 1) * 15),
            ]);
        }

        return null;
    }

    /** Why a non-word mark cannot reach the filing stage yet (no image on file), or null. */
    public function filingBlocker(Project $project, ProjectStage $target): ?string
    {
        if (! self::isTrademarkCode($project->service_code)) {
            return null;
        }

        $gateOrder = ProjectStage::where('project_id', $project->id)
            ->where('stage_name', config('trademark_lifecycle.image_gate_stage'))
            ->value('sequence_order');
        if ($gateOrder === null || $target->sequence_order < $gateOrder) {
            return null;
        }

        $application = $this->forProject($project);
        if (! $application || ! self::requiresImage($application->mark_type) || $application->mark_document_id) {
            return null;
        }

        return "Upload the {$application->mark_type} mark image before moving this case to \"{$target->stage_name}\".";
    }

    public function forProject(Project $project): ?TrademarkApplication
    {
        if (! $project->ip_record_id) {
            return null;
        }

        return TrademarkApplication::with('markDocument')->where('ip_record_id', $project->ip_record_id)->first();
    }

    /** Store (or replace) the mark representation image; the previous file stays as a DocumentVersion. */
    public function storeMarkImage(Project $project, TrademarkApplication $application, UploadedFile $file, User $user): Document
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $base = preg_replace('/[^\w\-]/', '_', $project->docket_number ?: "project-{$project->id}");
        $fileName = "{$base} - mark.{$extension}";

        $name = $fileName;
        for ($i = 1; Storage::disk('local')->exists(self::MARK_FOLDER_PATH.$name); $i++) {
            $name = "{$base} - mark ({$i}).{$extension}";
        }
        $path = $file->storeAs(rtrim(self::MARK_FOLDER_PATH, '/'), $name, 'local');

        try {
            return DB::transaction(function () use ($project, $application, $file, $user, $fileName, $path): Document {
                $document = $application->mark_document_id
                    ? Document::lockForUpdate()->find($application->mark_document_id)
                    : null;

                if ($document) {
                    $version = $document->current_version + 1;
                    $document->update([
                        'file_name' => $fileName, 'file_type' => $file->getClientMimeType(),
                        'file_size' => $file->getSize(), 'storage_path' => $path, 'current_version' => $version,
                    ]);
                } else {
                    $version = 1;
                    $document = Document::create([
                        'project_id' => $project->id,
                        'client_id' => $project->client_id,
                        'file_name' => $fileName,
                        'file_type' => $file->getClientMimeType(),
                        'file_size' => $file->getSize(),
                        'category' => self::MARK_FOLDER,
                        'storage_path' => $path,
                        'current_version' => 1,
                        'uploaded_by_id' => $user->id,
                        'status' => 'Draft',
                    ]);
                    $application->update(['mark_document_id' => $document->id]);
                }

                DocumentVersion::create([
                    'document_id' => $document->id,
                    'version_number' => $version,
                    'file_name' => $fileName,
                    'file_size' => $file->getSize(),
                    'storage_path' => $path,
                    'uploaded_by_id' => $user->id,
                ]);

                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'upload_trademark_mark',
                    'subject_type' => 'Project',
                    'subject_id' => $project->id,
                    'metadata' => ['document_id' => $document->id, 'path' => $path, 'version' => $version],
                    'ip_address' => request()?->ip(),
                    'user_agent' => request()?->userAgent(),
                ]);

                return $document;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    /** Serialisable view of a case's trademark details for the UI. */
    public function present(?TrademarkApplication $application): ?array
    {
        if (! $application) {
            return null;
        }

        return [
            'id' => $application->id,
            'ip_record_id' => $application->ip_record_id,
            'mark_text' => $application->mark_text,
            'mark_type' => $application->mark_type,
            'nice_classes' => $application->nice_classes ?? [],
            'requires_image' => self::requiresImage($application->mark_type),
            'image_path' => $application->markDocument?->storage_path,
            'image_name' => $application->markDocument?->file_name,
            'image_version' => $application->markDocument?->current_version,
        ];
    }
}
