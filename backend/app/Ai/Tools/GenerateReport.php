<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Http\Controllers\ReportsController;
use App\Models\User;
use App\Services\Reports\PaymentReportData;
use App\Services\Reports\ReportSigner;
use Illuminate\Http\Request;

class GenerateReport extends Tool
{
    public function name(): string { return 'generate_report'; }

    public function title(): string { return 'Generate a report file'; }

    public function description(): string
    {
        return 'Generate a Reports-tab report as a PDF, Excel (xlsx) or CSV file on the firm letterhead, limited to what the user may see. '
            .'It is saved in the user\'s Reports history as an UNSIGNED DRAFT and a download link valid for 30 minutes is returned; give the user the link. '
            .'To sign it, ask the user for the signatory PIN and call sign_report. Use list_reports for the report types.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['type', 'format'], 'properties' => [
            'type' => ['type' => 'string', 'description' => 'Report type from list_reports, e.g. payment-collection, financial-summary, matter-status, ip-deadline, overdue-cases, deadline-forecast.'],
            'format' => ['type' => 'string', 'enum' => ['pdf', 'xlsx', 'csv']],
            'from_date' => ['type' => 'string', 'format' => 'date'],
            'to_date' => ['type' => 'string', 'format' => 'date'],
            'client_code' => ['type' => 'string', 'description' => 'Only this client, e.g. 382M.'],
        ]];
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function handle(User $user, array $args): array
    {
        $type = (string) ($args['type'] ?? '');
        if (! array_key_exists($type, ReportsController::availableTo($user))) {
            throw new \InvalidArgumentException("You can't run the report \"{$type}\". Use list_reports to see the reports available to you.");
        }
        $file = in_array($args['format'] ?? '', ['pdf', 'xlsx', 'csv'], true) ? $args['format'] : 'pdf';
        $input = array_filter(['type' => $type, 'file' => $file, 'from_date' => $this->str($args, 'from_date'),
            'to_date' => $this->str($args, 'to_date'), 'client_code' => $this->str($args, 'client_code')], fn ($v) => $v !== null);
        $request = Request::create('/api/reports/download', 'POST', $input);
        $request->setUserResolver(fn () => $user);

        [$export] = app(ReportsController::class)->createExport($request, app(PaymentReportData::class), app(ReportSigner::class), ['source' => 'claude']);
        $link = $this->fileLink($user, 'report', $export->id, $file, [], str_replace(' ', '-', $export->report_name).".{$file}");

        return [
            'export_id' => $export->id, 'report' => $export->report_name, 'format' => $file, 'rows' => (int) $export->row_count,
            'filters' => $export->filters, 'signed' => false, 'status' => 'UNSIGNED DRAFT',
            'download_url' => $link['url'], 'link_expires' => $link['expires_at'],
            'files' => [$link],
        ];
    }
}
