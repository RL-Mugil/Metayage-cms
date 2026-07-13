<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\ReportExport;
use App\Models\TimeEntry;
use App\Models\Task;
use App\Models\TrackerRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportsController extends Controller
{
    private const REPORT_ACCESS = [
        'client-portfolio' => ['super_admin', 'partner', 'manager', 'galvanizer'],
        'matter-status' => ['super_admin', 'partner', 'manager', 'finance', 'galvanizer'],
        'financial-summary' => ['super_admin', 'partner', 'manager', 'finance', 'galvanizer'],
        'hrms' => ['super_admin', 'partner', 'hr'],
        'ip-deadline' => ['super_admin', 'partner', 'manager', 'galvanizer'],
        'productivity' => ['super_admin', 'partner', 'manager', 'galvanizer'],
        'tracker-workload' => ['super_admin', 'partner', 'manager', 'galvanizer'],
        'overdue-cases' => ['super_admin', 'partner', 'manager', 'galvanizer'],
        'deadline-forecast' => ['super_admin', 'partner', 'manager', 'galvanizer'],
        'payment-collection' => ['super_admin', 'partner', 'manager', 'finance', 'galvanizer'],
    ];

    private const REPORT_NAMES = [
        'client-portfolio' => 'Client Portfolio Report',
        'matter-status' => 'Case Status Report',
        'financial-summary' => 'Financial Summary',
        'hrms' => 'HRMS Report',
        'ip-deadline' => 'IP Deadline Report',
        'productivity' => 'Productivity Report',
        'tracker-workload' => 'Team Workload Report',
        'overdue-cases' => 'Overdue Cases Report',
        'deadline-forecast' => 'Deadline Forecast',
        'payment-collection' => 'Payment Collection',
    ];

    public function getData(Request $request): JsonResponse
    {
        [$type, $user, $perPage, $page, $from, $to] = $this->resolveContext($request);
        $payload = $this->buildReportPayload($type, $user, $perPage, $page, $from, $to);

        return response()->json($payload);
    }

    public function generate(Request $request): JsonResponse
    {
        [$type, $user, $perPage, $page, $from, $to] = $this->resolveContext($request);
        $format = strtoupper((string) $request->input('format', 'PDF'));
        if (! in_array($format, ['PDF', 'CSV', 'EXCEL'], true)) {
            $format = 'PDF';
        }

        $payload = $this->buildReportPayload($type, $user, $perPage, $page, $from, $to);

        $export = ReportExport::create([
            'generated_by_id' => $user->id,
            'report_type' => $type,
            'report_name' => self::REPORT_NAMES[$type] ?? $type,
            'format' => $format,
            'filters' => [
                'from_date' => $from?->toDateString(),
                'to_date' => $to?->toDateString(),
            ],
            'row_count' => count($payload['rows']),
            'snapshot' => $payload['rows'],
        ]);

        $payload['export_id'] = $export->id;

        return response()->json($payload);
    }

    public function history(Request $request): JsonResponse
    {
        $exports = ReportExport::with('generatedBy:id,name')
            ->where('generated_by_id', $request->user()->id)
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn (ReportExport $export) => [
                'id' => $export->id,
                'name' => $export->report_name,
                'type' => $export->report_type,
                'generated_by' => $export->generatedBy?->name,
                'generated_at' => $export->created_at?->toDateTimeString(),
                'format' => strtoupper($export->format),
                'row_count' => $export->row_count,
                'filters' => $export->filters ?? [],
            ]);

        return response()->json($exports);
    }

    public function showHistory(Request $request, int $id): JsonResponse
    {
        $export = ReportExport::where('generated_by_id', $request->user()->id)->findOrFail($id);

        return response()->json([
            'id' => $export->id,
            'name' => $export->report_name,
            'type' => $export->report_type,
            'format' => strtoupper($export->format),
            'generated_at' => $export->created_at?->toDateTimeString(),
            'rows' => $export->snapshot ?? [],
            'filters' => $export->filters ?? [],
        ]);
    }

    private function resolveContext(Request $request): array
    {
        $user = $request->user();
        $type = (string) $request->get('type', 'matter-status');
        $perPage = max(1, min((int) $request->get('per_page', 100), 1000));
        $page = max(1, (int) $request->get('page', 1));
        $from = $request->filled('from_date') ? Carbon::parse((string) $request->get('from_date'))->startOfDay() : null;
        $to = $request->filled('to_date') ? Carbon::parse((string) $request->get('to_date'))->endOfDay() : null;

        $allowedRoles = self::REPORT_ACCESS[$type] ?? ['super_admin'];
        abort_unless(in_array($user->role, $allowedRoles, true), 403, 'Forbidden');

        return [$type, $user, $perPage, $page, $from, $to];
    }

    private function buildReportPayload(string $type, $user, int $perPage, int $page, ?Carbon $from, ?Carbon $to): array
    {
        return match ($type) {
            'client-portfolio' => $this->clientPortfolio($user, $perPage, $page, $from, $to),
            'matter-status' => $this->matterStatus($user, $perPage, $page, $from, $to),
            'financial-summary' => $this->financialSummary($user, $perPage, $page, $from, $to),
            'hrms' => $this->hrmsReport($perPage, $page, $from, $to),
            'ip-deadline' => $this->ipDeadline($user, $perPage, $page, $from, $to),
            'productivity' => $this->productivity($user, $perPage, $page, $from, $to),
            'tracker-workload' => $this->trackerWorkload($user, $perPage, $page, $from, $to),
            'overdue-cases' => $this->overdueCases($user, $perPage, $page, $from, $to),
            'deadline-forecast' => $this->deadlineForecast($user, $perPage, $page, $from, $to),
            'payment-collection' => $this->paymentCollection($user, $perPage, $page, $from, $to),
            default => [
                'type' => $type,
                'rows' => [],
                'total' => 0,
                'per_page' => $perPage,
                'current_page' => 1,
                'last_page' => 1,
                'generated_at' => now()->toDateTimeString(),
            ],
        };
    }

    private function clientPortfolio($user, int $perPage, int $page, ?Carbon $from, ?Carbon $to): array
    {
        $query = Client::with('contacts', 'accountManager')
            ->withCount([
                'projects as projects_count' => fn ($q) => $this->applyProjectOperationalDateRange($q, $from, $to),
                'projects as active_projects_count' => fn ($q) => $this->applyProjectOperationalDateRange($q, $from, $to)
                    ->whereNotIn('status', ['Completed', 'Closed', 'Granted']),
            ]);

        if ($from || $to) {
            $query->whereHas('projects', fn ($q) => $this->applyProjectOperationalDateRange($q, $from, $to));
        }

        if ($user->role === 'manager') {
            $query->where('account_manager_id', $user->id);
        } elseif ($user->isGalvanizer()) {
            $user->applyClientScope($query);
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $rows = $paginator->getCollection()->map(fn ($client) => [
            'client_code' => $client->client_code,
            'company_name' => $client->company_name,
            'entity_type' => $client->entity_type,
            'primary_jurisdiction' => $client->primary_jurisdiction,
            'gst_type' => $client->gst_type,
            'status' => $client->status,
            'projects_count' => $client->projects_count,
            'active_projects_count' => $client->active_projects_count,
            'account_manager' => $client->accountManager?->name,
        ]);

        return $this->paginatedResponse('client-portfolio', $rows, $paginator, $perPage);
    }

    private function matterStatus($user, int $perPage, int $page, ?Carbon $from, ?Carbon $to): array
    {
        $query = Project::with('client', 'manager', 'stages');
        $this->applyProjectOperationalDateRange($query, $from, $to);
        if ($user->role === 'manager') {
            $query->where('assigned_manager_id', $user->id);
        } elseif ($user->isGalvanizer()) {
            $user->applyProjectScope($query);
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $rows = $paginator->getCollection()->map(fn ($project) => [
            'project_code' => $project->project_code,
            'project_name' => $project->project_name,
            'client' => $project->client?->company_name,
            'project_type' => $project->project_type,
            'status' => $project->status,
            'urgency' => $project->urgency,
            'current_stage' => $project->stages?->firstWhere('status', 'In Progress')?->stage_name ?? 'Invention Disclosure',
            'hard_deadline' => $project->hard_deadline,
            'manager' => $project->manager?->name,
        ]);

        return $this->paginatedResponse('matter-status', $rows, $paginator, $perPage);
    }

    private function financialSummary($user, int $perPage, int $page, ?Carbon $from, ?Carbon $to): array
    {
        $query = Invoice::with('client');
        $this->applyDateRange($query, 'issue_date', $from, $to);
        if ($user->role === 'manager') {
            $query->whereHas('project', fn ($q) => $q->where('assigned_manager_id', $user->id));
        } elseif ($user->isGalvanizer()) {
            $query->whereHas('project', fn ($q) => $user->applyProjectScope($q));
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $rows = $paginator->getCollection()->map(fn ($invoice) => [
            'invoice_code' => $invoice->invoice_code,
            'client' => $invoice->client?->company_name,
            'issue_date' => $invoice->issue_date,
            'due_date' => $invoice->due_date,
            'total_amount' => $invoice->total_amount,
            'balance_due' => $invoice->balance_due,
            'status' => $invoice->status,
            'currency' => $invoice->currency,
        ]);

        return $this->paginatedResponse('financial-summary', $rows, $paginator, $perPage);
    }

    private function hrmsReport(int $perPage, int $page, ?Carbon $from, ?Carbon $to): array
    {
        $query = Employee::with('department', 'designation', 'user');
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $employeeIds = $paginator->getCollection()->pluck('id')->all();

        $attendance = DB::table('attendances')
            ->selectRaw("
                employee_id,
                SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) AS present_days,
                SUM(CASE WHEN status = 'Half Day' THEN 1 ELSE 0 END) AS half_days,
                SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) AS absent_days
            ")
            ->whereIn('employee_id', $employeeIds);
        $this->applyDateRange($attendance, 'attendance_date', $from, $to);
        $attendance = $attendance->groupBy('employee_id')->get()->keyBy('employee_id');

        $leave = DB::table('leave_requests')
            ->selectRaw("employee_id, COALESCE(SUM(total_days), 0) AS approved_leave_days")
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'Approved')
            ->when($from, fn ($q) => $q->whereDate('to_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('from_date', '<=', $to->toDateString()))
            ->groupBy('employee_id')
            ->get()
            ->keyBy('employee_id');

        $payroll = DB::table('payslips as ps')
            ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
            ->select('ps.employee_id', 'ps.lop_days', 'ps.net_pay', 'pr.period', 'pr.status')
            ->whereIn('ps.employee_id', $employeeIds)
            ->when($from, fn ($q) => $q->whereDate('pr.period', '>=', $from->copy()->startOfMonth()->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('pr.period', '<=', $to->copy()->startOfMonth()->toDateString()))
            ->orderByDesc('pr.period')
            ->get()
            ->unique('employee_id')
            ->keyBy('employee_id');

        $rows = $paginator->getCollection()->map(function ($employee) use ($attendance, $leave, $payroll) {
            $attendanceRow = $attendance->get($employee->id);
            $leaveRow = $leave->get($employee->id);
            $payrollRow = $payroll->get($employee->id);

            return [
                'employee_code' => $employee->employee_code,
                'full_name' => $employee->full_name,
                'department' => $employee->department?->name,
                'designation' => $employee->designation?->title,
                'employment_status' => $employee->employment_status,
                'present_days' => (int) ($attendanceRow->present_days ?? 0),
                'half_days' => (int) ($attendanceRow->half_days ?? 0),
                'absent_days' => (int) ($attendanceRow->absent_days ?? 0),
                'approved_leave_days' => (float) ($leaveRow->approved_leave_days ?? 0),
                'latest_payroll_period' => $payrollRow->period ?? null,
                'latest_payroll_status' => $payrollRow->status ?? null,
                'latest_net_pay' => $payrollRow->net_pay ?? null,
                'lop_days' => $payrollRow->lop_days ?? null,
            ];
        });

        return $this->paginatedResponse('hrms', $rows, $paginator, $perPage);
    }

    private function ipDeadline($user, int $perPage, int $page, ?Carbon $from, ?Carbon $to): array
    {
        $query = Project::with('client')->whereNotNull('hard_deadline');
        $this->applyDateRange($query, 'hard_deadline', $from, $to);
        if ($user->role === 'manager') {
            $query->where('assigned_manager_id', $user->id);
        } elseif ($user->isGalvanizer()) {
            $user->applyProjectScope($query);
        }

        $paginator = $query->orderBy('hard_deadline')->paginate($perPage, ['*'], 'page', $page);
        $rows = $paginator->getCollection()->map(fn ($project) => [
            'project_code' => $project->project_code,
            'project_name' => $project->project_name,
            'client' => $project->client?->company_name,
            'project_type' => $project->project_type,
            'deadline' => $project->hard_deadline,
            'days_left' => Carbon::parse($project->hard_deadline)->diffInDays(now(), false) * -1,
            'urgency' => $project->urgency,
            'status' => $project->status,
        ]);

        return $this->paginatedResponse('ip-deadline', $rows, $paginator, $perPage);
    }

    private function productivity($user, int $perPage, int $page, ?Carbon $from, ?Carbon $to): array
    {
        $query = TimeEntry::with(['user', 'project', 'task', 'approvedBy']);
        $this->applyDateRange($query, 'entry_date', $from, $to);
        if ($user->isGalvanizer()) {
            $query->whereHas('project', fn ($q) => $user->applyProjectScope($q));
        }
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $rows = $paginator->getCollection()->map(fn ($entry) => [
            'entry_date' => $entry->entry_date?->toDateString(),
            'employee' => $entry->user?->name,
            'project_code' => $entry->project?->project_code,
            'task_title' => $entry->task?->title,
            'hours_logged' => $entry->duration_hours,
            'billable' => $entry->billable ? 'Yes' : 'No',
            'status' => $entry->status,
            'approved_by' => $entry->approvedBy?->name,
            'description' => $entry->description,
        ]);

        return $this->paginatedResponse('productivity', $rows, $paginator, $perPage);
    }

    private function trackerWorkload($user, int $perPage, int $page, ?Carbon $from, ?Carbon $to): array
    {
        $params = [];
        $rangeSql = $this->trackerDateSql($from, $to, $params);
        $circleSql = $this->trackerCircleSql($user);

        $rows = collect(DB::select("
            SELECT u.name AS team_member,
                   SUM(is_pcm) AS pcm_cases,
                   SUM(is_scm) AS scm_cases,
                   SUM(is_pr) AS pr_cases,
                   SUM(is_pcm + is_scm + is_pr) AS total_cases,
                   SUM(is_overdue) AS overdue
            FROM (
                SELECT pcm_id AS uid, 1 AS is_pcm, 0 AS is_scm, 0 AS is_pr,
                       CASE WHEN delivery_due_date < CURRENT_DATE THEN 1 ELSE 0 END AS is_overdue
                FROM tracker_rows
                WHERE pcm_id IS NOT NULL {$rangeSql} {$circleSql}
                UNION ALL
                SELECT scm_id AS uid, 0 AS is_pcm, 1 AS is_scm, 0 AS is_pr,
                       CASE WHEN delivery_due_date < CURRENT_DATE THEN 1 ELSE 0 END AS is_overdue
                FROM tracker_rows
                WHERE scm_id IS NOT NULL {$rangeSql} {$circleSql}
                UNION ALL
                SELECT pr_id AS uid, 0 AS is_pcm, 0 AS is_scm, 1 AS is_pr,
                       CASE WHEN delivery_due_date < CURRENT_DATE THEN 1 ELSE 0 END AS is_overdue
                FROM tracker_rows
                WHERE pr_id IS NOT NULL {$rangeSql} {$circleSql}
            ) t
            JOIN users u ON u.id = t.uid
            GROUP BY u.id, u.name
            ORDER BY total_cases DESC
        ", array_merge($params, $params, $params)))->map(fn ($row) => [
            'Team Member' => $row->team_member,
            'Total Cases' => (int) $row->total_cases,
            'PCM Cases' => (int) $row->pcm_cases,
            'SCM Cases' => (int) $row->scm_cases,
            'PR Cases' => (int) $row->pr_cases,
            'Overdue' => (int) $row->overdue,
        ]);

        return $this->collectionPaginatedResponse('tracker-workload', $rows, $perPage, $page);
    }

    private function overdueCases($user, int $perPage, int $page, ?Carbon $from, ?Carbon $to): array
    {
        $query = TrackerRow::with(['pcmUser:id,name', 'scmUser:id,name', 'prUser:id,name'])
            ->whereNotNull('delivery_due_date')
            ->whereDate('delivery_due_date', '<', now()->toDateString())
            ->orderBy('delivery_due_date');
        $this->applyDateRange($query, 'delivery_due_date', $from, $to);
        if ($user->isGalvanizer()) {
            $query->whereIn('circle_id', $this->allowedTrackerCircleIds($user));
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $rows = $paginator->getCollection()->map(fn ($row) => [
            'Docket #' => $row->docket_number ?? '—',
            'Client' => $row->client_name ?? '—',
            'Record Type' => $row->record_type ?? '—',
            'PCM' => $row->pcmUser?->name ?? '—',
            'SCM' => $row->scmUser?->name ?? '—',
            'PR' => $row->prUser?->name ?? '—',
            'Due Date' => $row->delivery_due_date?->toDateString() ?? '—',
            'Days Overdue' => $row->delivery_due_date ? (int) Carbon::parse($row->delivery_due_date)->diffInDays(now()) : 0,
            'Status' => $row->status ?? '—',
            'Payment' => $row->payment_status ?? '—',
        ]);

        return $this->paginatedResponse('overdue-cases', $rows, $paginator, $perPage);
    }

    private function deadlineForecast($user, int $perPage, int $page, ?Carbon $from, ?Carbon $to): array
    {
        $query = TrackerRow::with(['pcmUser:id,name'])
            ->whereNotNull('delivery_due_date')
            ->whereDate('delivery_due_date', '>=', now()->toDateString())
            ->orderBy('delivery_due_date');
        $this->applyDateRange($query, 'delivery_due_date', $from, $to);
        if ($user->isGalvanizer()) {
            $query->whereIn('circle_id', $this->allowedTrackerCircleIds($user));
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $rows = $paginator->getCollection()->map(fn ($row) => [
            'Docket #' => $row->docket_number ?? '—',
            'Client' => $row->client_name ?? '—',
            'Record Type' => $row->record_type ?? '—',
            'PCM' => $row->pcmUser?->name ?? '—',
            'Due Date' => $row->delivery_due_date?->toDateString() ?? '—',
            'Days Left' => $row->delivery_due_date ? (int) Carbon::parse($row->delivery_due_date)->diffInDays(now()) : '—',
            'Status' => $row->status ?? '—',
            '% Complete' => ($row->percentage_of_completion ?? 0) . '%',
            'Payment' => $row->payment_status ?? '—',
        ]);

        return $this->paginatedResponse('deadline-forecast', $rows, $paginator, $perPage);
    }

    private function paymentCollection($user, int $perPage, int $page, ?Carbon $from, ?Carbon $to): array
    {
        $query = DB::table('tracker_rows')->whereNotNull('client_name');
        $this->applyDateRange($query, 'delivery_due_date', $from, $to);
        if ($user->isGalvanizer()) {
            $query->whereIn('circle_id', $this->allowedTrackerCircleIds($user));
        }
        $paginator = $query
            ->selectRaw("
                client_name AS client,
                COUNT(*) AS total_cases,
                SUM(CASE WHEN payment_status = 'Paid' THEN 1 ELSE 0 END) AS paid,
                SUM(CASE WHEN payment_status = 'Partial' THEN 1 ELSE 0 END) AS partial,
                SUM(CASE WHEN payment_status = 'Pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN payment_status NOT IN ('Paid', 'Partial', 'Pending') OR payment_status IS NULL THEN 1 ELSE 0 END) AS not_set
            ")
            ->groupBy('client_name')
            ->orderByDesc('total_cases')
            ->paginate($perPage, ['*'], 'page', $page);

        $rows = collect($paginator->items())->map(fn ($row) => [
            'Client' => $row->client,
            'Total Cases' => (int) $row->total_cases,
            'Paid' => (int) $row->paid,
            'Partial' => (int) $row->partial,
            'Pending' => (int) $row->pending,
            'Not Set' => (int) $row->not_set,
        ]);

        return $this->paginatedResponse('payment-collection', $rows, $paginator, $perPage);
    }

    private function paginatedResponse(string $type, Collection $rows, $paginator, int $perPage): array
    {
        return [
            'type' => $type,
            'rows' => $rows->values()->all(),
            'total' => $paginator->total(),
            'per_page' => $perPage,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    private function collectionPaginatedResponse(string $type, Collection $rows, int $perPage, int $page): array
    {
        $total = $rows->count();

        return [
            'type' => $type,
            'rows' => $rows->forPage($page, $perPage)->values()->all(),
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int) ceil(max($total, 1) / $perPage),
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    private function applyDateRange($query, string $column, ?Carbon $from, ?Carbon $to): void
    {
        if ($from) {
            $query->whereDate($column, '>=', $from->toDateString());
        }

        if ($to) {
            $query->whereDate($column, '<=', $to->toDateString());
        }
    }

    private function trackerDateSql(?Carbon $from, ?Carbon $to, array &$params): string
    {
        $sql = '';
        if ($from) {
            $sql .= ' AND delivery_due_date >= ?';
            $params[] = $from->toDateString();
        }
        if ($to) {
            $sql .= ' AND delivery_due_date <= ?';
            $params[] = $to->toDateString();
        }

        return $sql;
    }

    private function trackerCircleSql($user): string
    {
        if (! $user->isGalvanizer()) {
            return '';
        }

        $ids = implode(',', array_map('intval', $this->allowedTrackerCircleIds($user)));

        return $ids !== '' ? " AND circle_id IN ({$ids})" : ' AND 1=0';
    }

    private function allowedTrackerCircleIds($user): array
    {
        if (! $user->isGalvanizer()) {
            return [];
        }

        return DB::table('tracker_circles')
            ->whereIn('slug', $user->galvanizerCircleSlugs())
            ->pluck('id')
            ->all();
    }

    private function applyProjectOperationalDateRange($query, ?Carbon $from, ?Carbon $to): void
    {
        if (! $from && ! $to) {
            return;
        }

        if ($from) {
            $date = $from->toDateString();
            $query->where(function ($q) use ($date) {
                $q->whereDate('hard_deadline', '>=', $date)
                    ->orWhere(function ($fallback) use ($date) {
                        $fallback->whereNull('hard_deadline')
                            ->whereDate('filing_date', '>=', $date);
                    })
                    ->orWhere(function ($fallback) use ($date) {
                        $fallback->whereNull('hard_deadline')
                            ->whereNull('filing_date')
                            ->whereDate('created_at', '>=', $date);
                    });
            });
        }

        if ($to) {
            $date = $to->toDateString();
            $query->where(function ($q) use ($date) {
                $q->whereDate('hard_deadline', '<=', $date)
                    ->orWhere(function ($fallback) use ($date) {
                        $fallback->whereNull('hard_deadline')
                            ->whereDate('filing_date', '<=', $date);
                    })
                    ->orWhere(function ($fallback) use ($date) {
                        $fallback->whereNull('hard_deadline')
                            ->whereNull('filing_date')
                            ->whereDate('created_at', '<=', $date);
                    });
            });
        }
    }
}
