<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateClientRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\PatentInvoiceIn;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\DataAccess;
use App\Support\Notifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Bulk Operations: one change applied to many cases, tasks, clients or invoices. Every item
 * goes through the same controller method a single edit uses (validation, permissions,
 * status side effects, stage dates, tracker sync, audit), so a bulk change can never do
 * something a one-by-one change could not. Each item reports ok or the reason it failed.
 *
 * Who: System Admin, Director, Accountant, HR and Paralegal on any record; Patent Analysts /
 * Engineers / Attorneys only on their CM / SCM cases (and those cases' tasks and clients);
 * galvanizers their circle. Deleting cases: System Admin only.
 */
class BulkController extends Controller
{
    public const CASE_ACTIONS = ['status', 'stage', 'assign', 'circle', 'deadline', 'notify', 'delete'];

    public const TASK_ACTIONS = ['status', 'assign', 'due_date'];

    public const CLIENT_ACTIONS = ['status', 'account_manager', 'circle'];

    public const INVOICE_ACTIONS = ['mark_sent', 'status_note', 'remind_client'];

    public const CLIENT_STATUSES = ['Active', 'Inactive', 'Prospect', 'On Hold'];

    public function execute(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->isClientRole() || $user->isInventor(), 403);

        $v = $request->validate([
            'entity' => ['required', Rule::in(['cases', 'tasks', 'clients', 'invoices'])],
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
            'action' => 'required|string|max:40',
            'params' => 'nullable|array',
        ]);
        $params = $v['params'] ?? [];
        $allowed = match ($v['entity']) {
            'cases' => self::CASE_ACTIONS, 'tasks' => self::TASK_ACTIONS, 'clients' => self::CLIENT_ACTIONS, default => self::INVOICE_ACTIONS,
        };
        abort_unless(in_array($v['action'], $allowed, true), 422, 'That action is not available for '.$v['entity'].'.');
        if ($v['entity'] === 'cases' && $v['action'] === 'delete') {
            abort_unless($user->role === 'super_admin', 403, 'Only a System Admin can delete cases in bulk.');
        }
        if ($v['entity'] === 'invoices' && $v['action'] === 'mark_sent') {
            abort_unless(DataAccess::canActOnFinance($user) || $user->isGalvanizer(), 403, 'Only Accounts, a Director, a System Admin or someone on the Financial access list can change invoices.');
        }

        $results = [];
        foreach (array_values(array_unique($v['ids'])) as $id) {
            try {
                $label = $this->runOne($request, $user, $v['entity'], $v['action'], (int) $id, $params);
                $results[] = ['id' => (int) $id, 'ok' => true, 'label' => $label];
            } catch (ValidationException $e) {
                $results[] = ['id' => (int) $id, 'ok' => false, 'error' => collect($e->errors())->flatten()->first() ?? 'Not valid.'];
            } catch (AuthorizationException $e) {
                $results[] = ['id' => (int) $id, 'ok' => false, 'error' => 'You may not change this record.'];
            } catch (HttpExceptionInterface $e) {
                $results[] = ['id' => (int) $id, 'ok' => false, 'error' => $e->getMessage() ?: 'Not allowed.'];
            }
        }
        $ok = count(array_filter($results, fn ($r) => $r['ok']));

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'bulk_'.$v['entity'].'_'.$v['action'],
            'metadata' => ['ids' => $v['ids'], 'params' => $params, 'ok' => $ok, 'failed' => count($results) - $ok],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['ok' => $ok, 'failed' => count($results) - $ok, 'results' => $results]);
    }

    /** Apply one action to one record; returns its display label. Throws on refusal. */
    private function runOne(Request $base, User $user, string $entity, string $action, int $id, array $p): string
    {
        return match ($entity) {
            'cases' => $this->runCase($base, $user, $action, $id, $p),
            'tasks' => $this->runTask($base, $user, $action, $id, $p),
            'clients' => $this->runClient($base, $user, $action, $id, $p),
            default => $this->runInvoice($base, $user, $action, $id, $p),
        };
    }

    private function runCase(Request $base, User $user, string $action, int $id, array $p): string
    {
        $project = Project::findOrFail($id);
        $this->assertCaseInScope($user, $project);
        $label = (string) ($project->docket_number ?: $project->project_code);
        $controller = app(ProjectController::class);

        switch ($action) {
            case 'status':
                $data = ['status' => (string) ($p['status'] ?? '')];
                if (! empty($p['status_event_date'])) {
                    $data['status_event_date'] = $p['status_event_date'];
                }
                $this->check($controller->update($this->formRequest(UpdateProjectRequest::class, $base, $user, $id, $data, 'PUT'), $id));
                break;
            case 'stage':
                $req = $this->plainRequest($base, $user, ['stage_name' => (string) ($p['stage_name'] ?? '')]);
                $this->check($controller->updateStage($req, $id));
                break;
            case 'assign':
                $data = array_filter([
                    'assigned_manager_id' => $p['assigned_manager_id'] ?? null,
                    'secondary_manager_id' => $p['secondary_manager_id'] ?? null,
                    'patent_engineer_id' => $p['patent_engineer_id'] ?? null,
                ], fn ($val) => $val !== null && $val !== '');
                abort_if($data === [], 422, 'Choose who to assign.');
                $this->check($controller->update($this->formRequest(UpdateProjectRequest::class, $base, $user, $id, array_map('intval', $data), 'PUT'), $id));
                break;
            case 'circle':
                $this->check($controller->update($this->formRequest(UpdateProjectRequest::class, $base, $user, $id, ['circle' => (string) ($p['circle'] ?? '')], 'PUT'), $id));
                break;
            case 'deadline':
                if (! empty($p['hard_deadline'])) {
                    $date = Carbon::parse($p['hard_deadline'])->toDateString();
                } else {
                    abort_unless($project->hard_deadline, 422, 'This case has no deadline to shift.');
                    $date = Carbon::parse($project->hard_deadline)->addDays((int) ($p['shift_days'] ?? 0))->toDateString();
                }
                $this->check($controller->update($this->formRequest(UpdateProjectRequest::class, $base, $user, $id, ['hard_deadline' => $date], 'PUT'), $id));
                break;
            case 'notify':
                $title = trim((string) ($p['title'] ?? ''));
                $message = trim((string) ($p['message'] ?? ''));
                abort_if($title === '' || $message === '', 422, 'Enter a title and a message.');
                $to = collect([$project->assigned_manager_id, $project->secondary_manager_id, $project->patent_engineer_id]);
                if (! empty($p['include_client'])) {
                    $to = $to->merge($project->client?->portalUserIds() ?? []);
                }
                $to = $to->filter()->unique()->reject(fn ($uid) => (int) $uid === (int) $user->id)->values()->all();
                abort_if($to === [], 422, 'Nobody to notify on this case.');
                Notifier::push($to, 'bulk_message', $title, "[{$label}] {$message}", "/projects/{$project->id}");
                AuditLog::create(['user_id' => $user->id, 'action' => 'bulk_notify', 'subject_type' => 'Project', 'subject_id' => $project->id,
                    'metadata' => ['title' => $title, 'recipients' => $to], 'ip_address' => $base->ip(), 'user_agent' => $base->userAgent()]);
                break;
            case 'delete':
                $this->check($controller->destroy($this->plainRequest($base, $user, []), $id));
                AuditLog::create(['user_id' => $user->id, 'action' => 'bulk_delete', 'subject_type' => 'Project', 'subject_id' => $project->id,
                    'metadata' => ['docket' => $label], 'ip_address' => $base->ip(), 'user_agent' => $base->userAgent()]);
                break;
        }

        return $label;
    }

    private function runTask(Request $base, User $user, string $action, int $id, array $p): string
    {
        $task = Task::with('project')->findOrFail($id);
        if (! DataAccess::seesAllCases($user) && ! $user->isGalvanizer() && (int) $task->assignee_id !== (int) $user->id) {
            abort_unless($task->project && $this->managesCase($user, $task->project), 403, 'Not one of your cases.');
        }
        $data = match ($action) {
            'status' => ['status' => (string) ($p['status'] ?? '')],
            'assign' => ['assignee_id' => (int) ($p['assignee_id'] ?? 0)],
            default => ['due_date' => (string) ($p['due_date'] ?? '')],
        };
        $this->check(app(TaskController::class)->update($this->formRequest(UpdateTaskRequest::class, $base, $user, $id, $data, 'PUT'), $id));

        return (string) $task->title;
    }

    private function runClient(Request $base, User $user, string $action, int $id, array $p): string
    {
        $client = Client::findOrFail($id);
        if (! DataAccess::seesAllCases($user) && ! $user->isGalvanizer() && (int) $client->account_manager_id !== (int) $user->id) {
            abort_unless($client->projects()->where(fn ($q) => DataAccess::scopeManagedCases($q, $user))->exists(), 403, 'Not one of your clients.');
        }
        if ($action === 'status') {
            abort_unless(in_array($p['status'] ?? null, self::CLIENT_STATUSES, true), 422, 'Choose Active, Inactive, Prospect or On Hold.');
        }
        $data = match ($action) {
            'status' => ['status' => (string) ($p['status'] ?? '')],
            'account_manager' => ['account_manager_id' => (int) ($p['account_manager_id'] ?? 0)],
            default => ['circle' => (string) ($p['circle'] ?? '')],
        };
        $this->check(app(ClientController::class)->update($this->formRequest(UpdateClientRequest::class, $base, $user, $id, $data, 'PUT'), $id));

        return (string) ($client->client_code ?: $client->legal_name);
    }

    private function runInvoice(Request $base, User $user, string $action, int $id, array $p): string
    {
        $invoice = PatentInvoiceIn::findOrFail($id);
        $visible = PatentInvoiceIn::query()->whereKey($id);
        DataAccess::scopeFinance($visible, $user);
        abort_unless(DataAccess::canOpenFinance($user) && $visible->exists(), 403, 'Not one of your invoices.');
        $label = (string) $invoice->invoice_uin;

        switch ($action) {
            case 'mark_sent':
                abort_if($invoice->isFromZoho(), 422, 'Zoho sets the status of an invoice raised in Zoho.');
                abort_if(in_array($invoice->status, ['Cancelled', 'Sent'], true), 422, "Already {$invoice->status}.");
                $this->check(app(PatentInvoiceController::class)->batch($this->plainRequest($base, $user, ['ids' => [$id], 'action' => 'mark_sent'])));
                break;
            case 'status_note':
                $note = trim((string) ($p['status_note'] ?? ''));
                abort_if($note === '', 422, 'Write the follow-up note.');
                $this->check(app(RenewalActionController::class)->postStatusNote($this->plainRequest($base, $user, ['status_note' => $note]), $id));
                break;
            case 'remind_client':
                $to = $invoice->client_id ? (Client::find($invoice->client_id)?->portalUserIds() ?? []) : [];
                abort_if($to === [], 422, 'This client has no portal login to remind.');
                $balance = number_format((float) ($invoice->balance_due ?? $invoice->invoice_amount), 2);
                Notifier::push($to, 'payment_reminder', 'Payment reminder',
                    trim((string) ($p['message'] ?? '')) ?: "Invoice {$label} has {$invoice->currency} {$balance} outstanding. Please arrange payment or share the payment proof.",
                    '/pending-payments');
                AuditLog::create(['user_id' => $user->id, 'action' => 'payment_reminder_sent', 'subject_type' => 'PatentInvoiceIn', 'subject_id' => $id,
                    'metadata' => ['recipients' => $to], 'ip_address' => $base->ip(), 'user_agent' => $base->userAgent()]);
                break;
        }

        return $label;
    }

    /** Scoped staff act only on their CM / SCM cases; galvanizers on their circle. */
    private function assertCaseInScope(User $user, Project $project): void
    {
        if (DataAccess::seesAllCases($user)) {
            return;
        }
        if ($user->isGalvanizer()) {
            abort_unless($user->canAccessCircle($project->circle), 403, 'Outside your circle.');

            return;
        }
        abort_unless($this->managesCase($user, $project), 403, 'Not one of your cases (you are not its Client Manager or Secondary CM).');
    }

    private function managesCase(User $user, Project $project): bool
    {
        return in_array((int) $user->id, [(int) $project->assigned_manager_id, (int) $project->secondary_manager_id], true);
    }

    /** A form request for a single-item controller method, validated and authorised as a real one would be. */
    private function formRequest(string $class, Request $base, User $user, int $id, array $input, string $method): FormRequest
    {
        /** @var FormRequest $req */
        $req = $class::create('/api/bulk/'.$id, $method, [], [], [], $this->jsonServer($base), json_encode($input));
        $route = new Route($method, 'api/bulk/{id}', []);
        $route->bind($req);
        $route->setParameter('id', (string) $id);
        $req->setRouteResolver(fn () => $route);
        $req->setUserResolver(fn () => $user);
        $req->setContainer(app())->setRedirector(app('redirect'));
        $req->validateResolved();

        return $req;
    }

    private function plainRequest(Request $base, User $user, array $input): Request
    {
        $req = Request::create('/api/bulk', 'POST', [], [], [], $this->jsonServer($base), json_encode($input));
        $req->setUserResolver(fn () => $user);

        return $req;
    }

    /**
     * Server values for an inner request carrying its own JSON body (the outer request's
     * JSON body must not be read in its place).
     */
    private function jsonServer(Request $base): array
    {
        return array_merge($base->server->all(), ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json']);
    }

    /** A controller answered with an error status: surface its message as this item's failure. */
    private function check($response): void
    {
        if ($response instanceof \Symfony\Component\HttpFoundation\Response && $response->getStatusCode() >= 400) {
            $body = json_decode((string) $response->getContent(), true);
            abort($response->getStatusCode(), (string) ($body['message'] ?? 'Not allowed.'));
        }
    }
}
