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
                // Aggregate over all rows — bounded at 5000 to cap memory
                $trackerRows = TrackerRow::limit(5000)->get();
                $today = now()->startOfDay();
                $workload = [];
                foreach ($trackerRows as $r) {
                    foreach (['pcm', 'scm', 'pr'] as $field) {
                        $name = trim($r->$field ?? '');
                        if (!$name) continue;
                        if (!isset($workload[$name])) $workload[$name] = ['Team Member' => $name, 'Total Cases' => 0, 'PCM Cases' => 0, 'SCM Cases' => 0, 'PR Cases' => 0, 'Overdue' => 0];
                        $workload[$name]['Total Cases']++;
                        $workload[$name][strtoupper($field) . ' Cases']++;
                        if ($r->delivery_due_date && Carbon::parse($r->delivery_due_date)->lt($today)) {
                            $workload[$name]['Overdue']++;
                        }
                    }
                }
                usort($workload, fn($a, $b) => $b['Total Cases'] - $a['Total Cases']);
                $data = collect(array_values($workload));
                return $this->collectionPaginatedResponse($type, $data, $perPage, $page);

            case 'overdue-cases':
                $today = now()->startOfDay();
                $paginator = TrackerRow::whereNotNull('delivery_due_date')
                    ->whereDate('delivery_due_date', '<', $today)
                    ->orderBy('delivery_due_date')
                    ->paginate($perPage, ['*'], 'page', $page);
                $rows = $paginator->getCollection()->map(fn($r) => [
                    'Docket #'       => $r->docket_number ?? '—',
                    'Client'         => $r->client_name ?? '—',
                    'Record Type'    => $r->record_type ?? '—',
                    'PCM'            => $r->pcm ?? '—',
                    'SCM'            => $r->scm ?? '—',
                    'PR'             => $r->pr ?? '—',
                    'Due Date'       => $r->delivery_due_date?->toDateString() ?? '—',
                    'Days Overdue'   => $r->delivery_due_date ? (int) Carbon::parse($r->delivery_due_date)->diffInDays(now()) : 0,
                    'Status'         => $r->status ?? '—',
                    'Payment'        => $r->payment_status ?? '—',
                ]);
                return $this->paginatedResponse($type, $rows, $paginator, $perPage);

            case 'deadline-forecast':
                $paginator = TrackerRow::whereNotNull('delivery_due_date')
                    ->whereDate('delivery_due_date', '>=', now()->toDateString())
                    ->orderBy('delivery_due_date')
                    ->paginate($perPage, ['*'], 'page', $page);
                $rows = $paginator->getCollection()->map(fn($r) => [
                    'Docket #'      => $r->docket_number ?? '—',
                    'Client'        => $r->client_name ?? '—',
                    'Record Type'   => $r->record_type ?? '—',
                    'PCM'           => $r->pcm ?? '—',
                    'Due Date'      => $r->delivery_due_date?->toDateString() ?? '—',
                    'Days Left'     => $r->delivery_due_date ? (int) Carbon::parse($r->delivery_due_date)->diffInDays(now()) : '—',
                    'Status'        => $r->status ?? '—',
                    '% Complete'    => $r->percentage_of_completion . '%',
                    'Payment'       => $r->payment_status ?? '—',
                ]);
                return $this->paginatedResponse($type, $rows, $paginator, $perPage);

            case 'payment-collection':
                // Aggregate over all tracker rows — bounded at 5000 to cap memory
                $data = TrackerRow::whereNotNull('client_name')
                    ->limit(5000)
                    ->get()
                    ->groupBy('client_name')
                    ->map(function ($clientRows, $client) {
                        $paid    = $clientRows->where('payment_status', 'Paid')->count();
                        $partial = $clientRows->where('payment_status', 'Partial')->count();
                        $pending = $clientRows->where('payment_status', 'Pending')->count();
                        $total   = $clientRows->count();
                        return [
                            'Client'       => $client,
                            'Total Cases'  => $total,
                            'Paid'         => $paid,
                            'Partial'      => $partial,
                            'Pending'      => $pending,
                            'Not Set'      => $total - $paid - $partial - $pending,
                        ];
                    })
                    ->sortByDesc('Total Cases')
                    ->values();
                return $this->collectionPaginatedResponse($type, $data, $perPage, $page);

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
