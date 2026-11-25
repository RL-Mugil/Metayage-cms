<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\ReportExport;
use App\Models\User;

class DownloadSavedReport extends Tool
{
    public function name(): string { return 'download_saved_report'; }

    public function title(): string { return 'Download a saved report'; }

    public function description(): string
    {
        return 'A fresh 30-minute download link for one of the user\'s saved reports (from report_history), as PDF, Excel or CSV, with its signature if signed.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['export_id'], 'properties' => [
            'export_id' => ['type' => 'integer'],
            'format' => ['type' => 'string', 'enum' => ['pdf', 'xlsx', 'csv']],
        ]];
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function handle(User $user, array $args): array
    {
        $export = ReportExport::where('generated_by_id', $user->id)->findOrFail((int) ($args['export_id'] ?? 0));
        $file = in_array($args['format'] ?? '', ['pdf', 'xlsx', 'csv'], true) ? $args['format'] : (['EXCEL' => 'xlsx', 'CSV' => 'csv'][$export->format] ?? 'pdf');
        $link = $this->fileLink($user, 'report', $export->id, $file, [], str_replace(' ', '-', $export->report_name).".{$file}");

        return ['export_id' => $export->id, 'report' => $export->report_name, 'download_url' => $link['url'], 'link_expires' => $link['expires_at'], 'files' => [$link]];
    }
}
