<?php

namespace App\Services;

use App\Models\Inventor;
use App\Models\Project;
use App\Models\ProjectStage;
use Illuminate\Support\Facades\DB;

/**
 * Foreign filing permission: when a case is filed at a patent office other than an
 * inventor's home country, permission is needed from that country first (India: Form 25
 * under Sec. 39; US: a foreign filing licence under 35 USC 184). This keeps one stage per
 * such country in the case's workflow, just before its first filing stage. Only cases
 * whose workflow has a filing stage get it (not office actions, renewals, searches).
 */
class ForeignFilingPermission
{
    public const STAGE_PREFIXES = ['Foreign filing permission', 'Foreign filing licence'];

    /** Services that file an application (a first filing abroad needs the permission). */
    public const FILING_SERVICES = [
        'PRV', 'PRD', 'DRP', 'CPT', 'CPD', 'CPE', 'CEE', 'CES', 'CPS', 'CTS', 'CPA', 'PRA', 'CVP', 'PCT', 'PIB',
        'NPV', 'PIA', 'NPE', 'NAP', 'NAF', 'NPA', 'NPS', 'NPD', 'NPP', 'CON', 'CTN', 'CNS', 'CIP', 'PCA',
        'DIV', 'DVA', 'PAD', 'DSN', 'FIL', 'TPS',
    ];

    /** Nationality words to country codes; a country code or name is accepted as well. */
    private const NATIONALITIES = [
        'INDIAN' => 'IN', 'AMERICAN' => 'US', 'US' => 'US', 'USA' => 'US', 'UNITED STATES' => 'US',
        'BRITISH' => 'GB', 'ENGLISH' => 'GB', 'UK' => 'GB', 'UNITED KINGDOM' => 'GB',
        'CHINESE' => 'CN', 'JAPANESE' => 'JP', 'KOREAN' => 'KR', 'SOUTH KOREAN' => 'KR', 'GERMAN' => 'DE',
        'FRENCH' => 'FR', 'ITALIAN' => 'IT', 'SPANISH' => 'ES', 'DUTCH' => 'NL', 'SWISS' => 'CH',
        'SWEDISH' => 'SE', 'DANISH' => 'DK', 'NORWEGIAN' => 'NO', 'FINNISH' => 'FI', 'IRISH' => 'IE',
        'CANADIAN' => 'CA', 'AUSTRALIAN' => 'AU', 'NEW ZEALANDER' => 'NZ', 'SINGAPOREAN' => 'SG',
        'MALAYSIAN' => 'MY', 'SRI LANKAN' => 'LK', 'BANGLADESHI' => 'BD', 'NEPALESE' => 'NP', 'NEPALI' => 'NP',
        'PAKISTANI' => 'PK', 'EMIRATI' => 'AE', 'UAE' => 'AE', 'SAUDI' => 'SA', 'ISRAELI' => 'IL',
        'RUSSIAN' => 'RU', 'BRAZILIAN' => 'BR', 'MEXICAN' => 'MX', 'SOUTH AFRICAN' => 'ZA', 'TAIWANESE' => 'TW',
        'THAI' => 'TH', 'INDONESIAN' => 'ID', 'FILIPINO' => 'PH', 'VIETNAMESE' => 'VN', 'BELGIAN' => 'BE',
        'AUSTRIAN' => 'AT', 'POLISH' => 'PL', 'PORTUGUESE' => 'PT', 'GREEK' => 'GR', 'TURKISH' => 'TR',
    ];

    /** Country code for a nationality as typed on the inventor ("Indian", "IN", "India"); null if unknown. */
    public static function countryOf(?string $nationality): ?string
    {
        $value = strtoupper(trim((string) $nationality));
        if ($value === '') {
            return null;
        }
        if (isset(self::NATIONALITIES[$value])) {
            return self::NATIONALITIES[$value];
        }
        $offices = config('project_import_codes.offices', []);
        if (strlen($value) === 2 && isset($offices[$value])) {
            return $value;
        }
        foreach ($offices as $code => $name) {
            if (strlen($code) === 2 && strtoupper((string) $name) === $value) {
                return $code;
            }
        }

        return null;
    }

    /**
     * The inventors' home countries that differ from the case's patent office:
     * code => display name. An unrecognised nationality is kept (by its own name) so the
     * step is never silently missed.
     *
     * @return array<string, string>
     */
    public function homeCountries(Project $project): array
    {
        $office = strtoupper((string) ($project->patent_office_code ?: 'IN'));
        $countries = [];
        foreach ($project->inventors()->get(['inventors.id', 'nationality']) as $inventor) {
            $nationality = trim((string) $inventor->nationality);
            if ($nationality === '') {
                continue;
            }
            $code = self::countryOf($nationality);
            if ($code === $office) {
                continue;
            }
            $key = $code ?? strtoupper($nationality);
            $countries[$key] = $code ? self::countryName($code) : $nationality;
        }

        return $countries;
    }

    public static function countryName(string $code): string
    {
        $name = (string) config("project_import_codes.offices.{$code}", $code);

        return match ($code) {
            'US' => 'US',
            'GB' => 'UK',
            default => ucwords(strtolower(preg_replace('/\s*\(.*$/', '', $name))),
        };
    }

    public static function stageName(string $code, string $country): string
    {
        return match ($code) {
            'IN' => 'Foreign filing permission – India (Form 25, Sec. 39)',
            'US' => 'Foreign filing licence – US (35 USC 184)',
            default => "Foreign filing permission – {$country}",
        };
    }

    public static function isPermissionStage(string $stageName): bool
    {
        foreach (self::STAGE_PREFIXES as $prefix) {
            if (str_starts_with($stageName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Adds the missing permission stages before the first filing stage and drops ones no longer needed. */
    public function sync(Project $project): void
    {
        DB::transaction(function () use ($project) {
            $stages = $project->stages()->orderBy('sequence_order')->get();
            $filesApplication = in_array(strtoupper((string) $project->service_code), self::FILING_SERVICES, true);
            $filing = $filesApplication ? $stages->first(fn (ProjectStage $stage) => ! self::isPermissionStage($stage->stage_name)
                && preg_match('/\b(filing|filed|file)\b/i', $stage->stage_name)) : null;

            $wanted = $filing ? $this->homeCountries($project) : [];
            $wantedNames = [];
            foreach ($wanted as $code => $country) {
                $wantedNames[] = self::stageName($code, $country);
            }

            // Remove permission stages no longer needed (never a completed one).
            foreach ($stages as $stage) {
                if (self::isPermissionStage($stage->stage_name) && ! in_array($stage->stage_name, $wantedNames, true) && $stage->status !== 'Completed') {
                    $stage->delete();
                }
            }

            $existing = $project->stages()->pluck('stage_name')->all();
            $missing = array_values(array_diff($wantedNames, $existing));
            if ($missing && $filing) {
                $at = (int) $filing->sequence_order;
                $project->stages()->where('sequence_order', '>=', $at)->increment('sequence_order', count($missing));
                // Added as a to-do (Pending): never marked done on the firm's behalf.
                foreach ($missing as $offset => $name) {
                    ProjectStage::create([
                        'project_id' => $project->id,
                        'stage_name' => $name,
                        'status' => 'Pending',
                        'sequence_order' => $at + $offset,
                        'duration_days' => 0,
                    ]);
                }
                // If the work has reached filing but nothing is in progress before it, the
                // permission becomes the current step (it must come before filing).
                $inProgressBefore = $project->stages()->where('sequence_order', '<', $at)->where('status', 'In Progress')->exists();
                if ($filing->status === 'In Progress' && ! $inProgressBefore) {
                    $project->stages()->where('stage_name', $missing[0])->update(['status' => 'In Progress', 'actual_start_at' => now()]);
                    $project->stages()->whereKey($filing->id)->update(['status' => 'Pending', 'actual_start_at' => null]);
                }
            }

            // Keep the order contiguous after removals.
            foreach ($project->stages()->orderBy('sequence_order')->get()->values() as $index => $stage) {
                if ((int) $stage->sequence_order !== $index) {
                    $stage->update(['sequence_order' => $index]);
                }
            }
        });
    }

    /** Re-checks every case an inventor is named on (after their nationality changes). */
    public function syncForInventor(Inventor $inventor): void
    {
        foreach ($inventor->projects()->get() as $project) {
            $this->sync($project);
        }
    }
}
