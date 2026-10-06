<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ZohoInvoice;

/** Links existing local Zoho mirrors when a matching portal case appears. */
class ZohoUinLinker
{
    public function linkProject(Project $project): int
    {
        $docket = strtoupper(trim((string) $project->docket_number));
        if (strlen($docket) < 9) {
            return 0;
        }
        $prefix = substr($docket, 0, 9);
        $samePrefix = Project::query()->whereNotNull('docket_number')
            ->whereRaw('UPPER(LEFT(docket_number, 9)) = ?', [$prefix])->count();
        if ($samePrefix !== 1) {
            return 0;
        }

        return ZohoInvoice::query()->whereNull('project_id')->where(function ($query) use ($prefix) {
            $query->whereRaw('UPPER(number) LIKE ?', [$prefix . '%']);
        })->where(function ($query) {
            $query->whereNull('match_source')->orWhere('match_source', '!=', 'manual');
        })->update([
            'project_id' => $project->id,
            'client_id' => $project->client_id,
            'match_source' => 'uin_prefix',
        ]);
    }
}
