<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Http\Controllers\ReportsController;
use App\Models\User;

class ListReports extends Tool
{
    public function name(): string { return 'list_reports'; }

    public function title(): string { return 'Available reports'; }

    public function description(): string
    {
        return 'The Reports-tab reports this user may generate (type code and name), with the filters each accepts. Use a type with generate_report.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => (object) []];
    }

    public function handle(User $user, array $args): array
    {
        $reports = collect(ReportsController::availableTo($user))->map(fn ($name, $type) => [
            'type' => $type, 'name' => $name,
            'filters' => in_array($type, ['hrms', 'productivity', 'tracker-workload', 'payroll-cost-summary', 'tds-pf-esi-summary'], true)
                ? ['from_date', 'to_date'] : ['from_date', 'to_date', 'client_code'],
        ])->values()->all();

        return ['formats' => ['pdf', 'xlsx', 'csv'], 'reports' => $reports,
            'note' => 'Reports are saved in Reports history as an unsigned draft; sign_report signs one with the signatory PIN.'];
    }
}
