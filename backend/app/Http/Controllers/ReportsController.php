<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\Invoice;
use App\Models\Employee;
use App\Models\Attendance;
use App\Models\TimeEntry;
use App\Models\TrackerRow;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportsController extends Controller
{
    private const REPORT_ACCESS = [
        'client-portfolio'  => ['super_admin', 'partner', 'manager'],
        'matter-status'     => ['super_admin', 'partner', 'manager', 'finance'],
        'financial-summary' => ['super_admin', 'partner', 'manager', 'finance'],
        'hrms'              => ['super_admin', 'partner', 'hr'],
        'ip-deadline'       => ['super_admin', 'partner', 'manager'],
        'productivity'      => ['super_admin', 'partner', 'manager'],
        'tracker-workload'  => ['super_admin', 'partner', 'manager'],
        'overdue-cases'     => ['super_admin', 'partner', 'manager'],
        'deadline-forecast' => ['super_admin', 'partner', 'manager'],
        'payment-collection'=> ['super_admin', 'partner', 'manager', 'finance'],
    ];

    public function getData(Request $request)
    {
        $user    = $request->user();
        $type    = $request->get('type', 'matter-status');
        $perPage = max(1, min((int) $request->get('per_page', 100), 1000));
        $page    = max(1, (int) $request->get('page', 1));

        $allowedRoles = self::REPORT_ACCESS[$type] ?? ['super_admin'];
        if (! in_array($user->role, $allowedRoles)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        switch ($type) {
            case 'client-portfolio':
                $paginator = Client::with('contacts', 'accountManager')
                    ->withCount('projects')
                    ->paginate($perPage, ['*'], 'page', $page);
                $rows = $paginator->getCollection()->map(fn($c) => [
                    'client_code'         => $c->client_code,
                    'company_name'        => $c->company_name,
                    'entity_type'         => $c->entity_type,
                    'primary_jurisdiction'=> $c->primary_jurisdiction,
                    'gst_type'            => $c->gst_type,
                    'status'              => $c->status,
                    'projects_count'      => $c->projects_count,
                    'account_manager'     => $c->accountManager?->name,
                ]);
                return $this->paginatedResponse($type, $rows, $paginator, $perPage);

            case 'matter-status':
                $paginator = Project::with('client', 'manager', 'stages')
                    ->paginate($perPage, ['*'], 'page', $page);
                $rows = $paginator->getCollection()->map(fn($p) => [
                    'project_code'  => $p->project_code,
                    'project_name'  => $p->project_name,
                    'client'        => $p->client?->company_name,
                    'project_type'  => $p->project_type,
                    'status'        => $p->status,
                    'urgency'       => $p->urgency,
                    'current_stage' => $p->stages?->firstWhere('status', 'In Progress')?->stage_name ?? 'Intake',
                    'hard_deadline' => $p->hard_deadline,
                    'manager'       => $p->manager?->name,
                ]);
                return $this->paginatedResponse($type, $rows, $paginator, $perPage);

            case 'financial-summary':
                $paginator = Invoice::with('client')
                    ->paginate($perPage, ['*'], 'page', $page);
                $rows = $paginator->getCollection()->map(fn($i) => [
                    'invoice_code'  => $i->invoice_code,
                    'client'        => $i->client?->company_name,
                    'issue_date'    => $i->issue_date,
                    'due_date'      => $i->due_date,
                    'total_amount'  => $i->total_amount,
                    'balance_due'   => $i->balance_due,
                    'status'        => $i->status,
                    'currency'      => $i->currency,
                ]);
                return $this->paginatedResponse($type, $rows, $paginator, $perPage);

            case 'hrms':
                $paginator = Employee::with('department', 'designation', 'user')
                    ->paginate($perPage, ['*'], 'page', $page);
                $rows = $paginator->getCollection()->map(fn($e) => [
                    'employee_code'     => $e->employee_code,
                    'full_name'         => $e->full_name,
                    'work_email'        => $e->work_email,
                    'department'        => $e->department?->name,
                    'designation'       => $e->designation?->title,
                    'employment_status' => $e->employment_status,
                    'work_location'     => $e->work_location,
                    'date_of_joining'   => $e->date_of_joining,
                ]);
                return $this->paginatedResponse($type, $rows, $paginator, $perPage);

            case 'ip-deadline':
                $paginator = Project::with('client')
                    ->whereNotNull('hard_deadline')
                    ->orderBy('hard_deadline')
                    ->paginate($perPage, ['*'], 'page', $page);
                $rows = $paginator->getCollection()->map(fn($p) => [
                    'project_code' => $p->project_code,
                    'project_name' => $p->project_name,
                    'client'       => $p->client?->company_name,
                    'project_type' => $p->project_type,
                    'deadline'     => $p->hard_deadline,
                    'days_left'    => Carbon::parse($p->hard_deadline)->diffInDays(now(), false) * -1,
                    'urgency'      => $p->urgency,
                    'status'       => $p->status,
                ]);
                return $this->paginatedResponse($type, $rows, $paginator, $perPage);

            case 'productivity':
                $paginator = Task::with('assignee', 'project')
                    ->paginate($perPage, ['*'], 'page', $page);
                $rows = $paginator->getCollection()->map(fn($t) => [
                    'title'          => $t->title,
                    'project_code'   => $t->project?->project_code,
                    'assignee'       => $t->assignee?->name,
                    'status'         => $t->status,
                    'priority'       => $t->priority,
                    'due_date'       => $t->due_date,
                    'actual_hours'   => $t->actual_hours,
                ]);
                return $this->paginatedResponse($type, $rows, $paginator, $perPage);

            case 'tracker-workload':
                // SQL aggregation via UNION — returns one row per (user, role) pair, no PHP loop
                $rawWorkload = \DB::select("
                    SELECT u.name as team_member,
                           SUM(is_pcm) as pcm_cases,
                           SUM(is_scm) as scm_cases,
                           SUM(is_pr)  as pr_cases,
                           SUM(is_pcm + is_scm + is_pr) as total_cases,
                           SUM(is_overdue) as overdue
                    FROM (
                        SELECT pcm_id as uid,
                               1 as is_pcm, 0 as is_scm, 0 as is_pr,
                               (CASE WHEN delivery_due_date < CURRENT_DATE THEN 1 ELSE 0 END) as is_overdue
                        FROM tracker_rows WHERE pcm_id IS NOT NULL
                        UNION ALL
                        SELECT scm_id, 0, 1, 0,
                               (CASE WHEN delivery_due_date < CURRENT_DATE THEN 1 ELSE 0 END)
                        FROM tracker_rows WHERE scm_id IS NOT NULL
                        UNION ALL
                        SELECT pr_id, 0, 0, 1,
                               (CASE WHEN delivery_due_date < CURRENT_DATE THEN 1 ELSE 0 END)
                        FROM tracker_rows WHERE pr_id IS NOT NULL
                    ) t
                    JOIN users u ON u.id = t.uid
                    GROUP BY u.id, u.name
                    ORDER BY total_cases DESC
                ");
                $data = collect($rawWorkload)->map(fn($r) => [
                    'Team Member' => $r->team_member,
                    'Total Cases' => (int) $r->total_cases,
                    'PCM Cases'   => (int) $r->pcm_cases,
                    'SCM Cases'   => (int) $r->scm_cases,
                    'PR Cases'    => (int) $r->pr_cases,
                    'Overdue'     => (int) $r->overdue,
                ]);
                return $this->collectionPaginatedResponse($type, $data, $perPage, $page);

            case 'overdue-cases':
                $today = now()->startOfDay();
                $paginator = TrackerRow::with(['pcmUser:id,name', 'scmUser:id,name', 'prUser:id,name'])
                    ->whereNotNull('delivery_due_date')
                    ->whereDate('delivery_due_date', '<', $today)
                    ->orderBy('delivery_due_date')
                    ->paginate($perPage, ['*'], 'page', $page);
                $rows = $paginator->getCollection()->map(fn($r) => [
                    'Docket #'       => $r->docket_number ?? '—',
                    'Client'         => $r->client_name ?? '—',
                    'Record Type'    => $r->record_type ?? '—',
                    'PCM'            => $r->pcmUser?->name ?? '—',
                    'SCM'            => $r->scmUser?->name ?? '—',
                    'PR'             => $r->prUser?->name ?? '—',
                    'Due Date'       => $r->delivery_due_date?->toDateString() ?? '—',
                    'Days Overdue'   => $r->delivery_due_date ? (int) Carbon::parse($r->delivery_due_date)->diffInDays(now()) : 0,
                    'Status'         => $r->status ?? '—',
                    'Payment'        => $r->payment_status ?? '—',
                ]);
                return $this->paginatedResponse($type, $rows, $paginator, $perPage);

            case 'deadline-forecast':
                $paginator = TrackerRow::with(['pcmUser:id,name'])
                    ->whereNotNull('delivery_due_date')
                    ->whereDate('delivery_due_date', '>=', now()->toDateString())
                    ->orderBy('delivery_due_date')
                    ->paginate($perPage, ['*'], 'page', $page);
                $rows = $paginator->getCollection()->map(fn($r) => [
                    'Docket #'      => $r->docket_number ?? '—',
                    'Client'        => $r->client_name ?? '—',
                    'Record Type'   => $r->record_type ?? '—',
                    'PCM'           => $r->pcmUser?->name ?? '—',
                    'Due Date'      => $r->delivery_due_date?->toDateString() ?? '—',
                    'Days Left'     => $r->delivery_due_date ? (int) Carbon::parse($r->delivery_due_date)->diffInDays(now()) : '—',
                    'Status'        => $r->status ?? '—',
                    '% Complete'    => $r->percentage_of_completion . '%',
                    'Payment'       => $r->payment_status ?? '—',
                ]);
                return $this->paginatedResponse($type, $rows, $paginator, $perPage);

            case 'payment-collection':
                // Pure SQL GROUP BY — no PHP aggregation loop
                $payCol = \DB::table('tracker_rows')
                    ->whereNotNull('client_name')
                    ->selectRaw("
                        client_name as client,
                        COUNT(*) as total_cases,
                        SUM(CASE WHEN payment_status = 'Paid' THEN 1 ELSE 0 END) as paid,
                        SUM(CASE WHEN payment_status = 'Partial' THEN 1 ELSE 0 END) as partial,
                        SUM(CASE WHEN payment_status = 'Pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN payment_status NOT IN ('Paid','Partial','Pending') OR payment_status IS NULL THEN 1 ELSE 0 END) as not_set
                    ")
                    ->groupBy('client_name')
                    ->orderByDesc('total_cases')
                    ->paginate($perPage, ['*'], 'page', $page);

                $data = collect($payCol->items())->map(fn($r) => [
                    'Client'      => $r->client,
                    'Total Cases' => (int) $r->total_cases,
                    'Paid'        => (int) $r->paid,
                    'Partial'     => (int) $r->partial,
                    'Pending'     => (int) $r->pending,
                    'Not Set'     => (int) $r->not_set,
                ]);
                return $this->paginatedResponse($type, $data, $payCol, $perPage);

            default:
                return response()->json([
                    'type' => $type, 'rows' => [], 'total' => 0,
                    'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1,
                    'generated_at' => now()->toDateTimeString(),
                ]);
        }
    }

    private function paginatedResponse(string $type, $rows, $paginator, int $perPage): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'type'         => $type,
            'rows'         => $rows->values(),
            'total'        => $paginator->total(),
            'per_page'     => $perPage,
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'generated_at' => now()->toDateTimeString(),
        ]);
    }

    private function collectionPaginatedResponse(string $type, $data, int $perPage, int $page): \Illuminate\Http\JsonResponse
    {
        $total = $data->count();
        return response()->json([
            'type'         => $type,
            'rows'         => $data->forPage($page, $perPage)->values(),
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => (int) ceil($total / $perPage),
            'generated_at' => now()->toDateTimeString(),
        ]);
    }
}
