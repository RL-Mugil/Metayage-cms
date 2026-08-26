<?php

namespace App\Http\Controllers;

use App\Http\PaginationHelper;
use App\Http\Requests\StoreComplianceItemRequest;
use App\Http\Requests\UpdateComplianceItemRequest;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ComplianceItem;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ComplianceController extends Controller
{
    public function stats(Request $request)
    {
        $this->authorize('viewAny', ComplianceItem::class);
        $query = $this->visibleQuery($request)->where('status', '!=', 'Resolved');
        $this->applyFilters($query, $request, false);
        $today = Carbon::today();
        $row = $query->selectRaw(
            'SUM(CASE WHEN deadline <= ? THEN 1 ELSE 0 END) AS critical,
             SUM(CASE WHEN deadline BETWEEN ? AND ? THEN 1 ELSE 0 END) AS at_risk,
             SUM(CASE WHEN deadline BETWEEN ? AND ? THEN 1 ELSE 0 END) AS on_track,
             SUM(CASE WHEN deadline > ? THEN 1 ELSE 0 END) AS compliant',
            [$today->copy()->addDays(30), $today->copy()->addDays(31), $today->copy()->addDays(75),
             $today->copy()->addDays(76), $today->copy()->addDays(150), $today->copy()->addDays(150)]
        )->first();
        return response()->json(['critical' => (int) ($row?->critical ?? 0), 'at_risk' => (int) ($row?->at_risk ?? 0),
            'on_track' => (int) ($row?->on_track ?? 0), 'compliant' => (int) ($row?->compliant ?? 0)]);
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', ComplianceItem::class);
        $query = $this->visibleQuery($request)
            ->with(['client:id,client_code,legal_name,company_name', 'project:id,project_code,docket_number,application_number,circle'])
            ->where('status', '!=', 'Resolved')->orderBy('deadline');
        $this->applyFilters($query, $request);
        $result = PaginationHelper::paginate($query, $request, 50);
        $result['data'] = $result['data']->map(fn (ComplianceItem $item) => $this->transform($item));
        return response()->json($result);
    }

    public function store(StoreComplianceItemRequest $request)
    {
        $validated = $request->validated();
        $client = ! empty($validated['client_id']) ? Client::findOrFail($validated['client_id']) : null;
        $project = ! empty($validated['project_id']) ? Project::findOrFail($validated['project_id']) : null;
        if ($project && $client && (int) $project->client_id !== (int) $client->id) {
            return response()->json(['message' => 'The selected case does not belong to the selected client.'], 422);
        }
        if ($project && ! $client) {
            $validated['client_id'] = $project->client_id;
        }
        $item = DB::transaction(function () use ($request, $validated): ComplianceItem {
            $assignee = ! empty($validated['assignee_id']) ? User::find($validated['assignee_id']) : null;
            $item = ComplianceItem::create([
                ...collect($validated)->except(['note'])->all(), 'source_type' => 'manual',
                'source_key' => 'manual:' . Str::uuid(), 'assignee' => $assignee?->name, 'status' => 'Open',
                'notes' => ! empty($validated['note']) ? [['text' => $validated['note'], 'by' => $request->user()->name, 'at' => now()->toDateTimeString()]] : [],
            ]);
            $this->audit($request, 'create', $item, ['source_type' => 'manual']);
            return $item;
        });
        return response()->json($this->transform($item->load(['client', 'project'])), 201);
    }

    public function update(UpdateComplianceItemRequest $request, $id)
    {
        $item = ComplianceItem::findOrFail($id);
        $validated = $request->validated();
        DB::transaction(function () use ($request, $item, $validated): void {
            $before = $item->only(['assignee_id', 'status', 'resolved_at']);
            if (array_key_exists('assignee_id', $validated)) {
                $user = $validated['assignee_id'] ? User::findOrFail($validated['assignee_id']) : null;
                $item->assignee_id = $user?->id;
                $item->assignee = $user?->name;
            } elseif (array_key_exists('assignee', $validated)) {
                $user = $validated['assignee'] ? User::where('name', $validated['assignee'])->first() : null;
                $item->assignee_id = $user?->id;
                $item->assignee = $validated['assignee'];
            }
            if (! empty($validated['note'])) {
                $notes = $item->notes ?? [];
                $notes[] = ['text' => $validated['note'], 'by' => $request->user()->name, 'at' => now()->toDateTimeString()];
                $item->notes = $notes;
            }
            if (($validated['resolved'] ?? false) === true) {
                $item->status = 'Resolved';
                $item->resolved_at = now();
            }
            $item->save();
            $this->audit($request, 'update', $item, ['before' => $before, 'changes' => $item->getChanges()]);
        });
        return response()->json(['ok' => true, 'item' => $this->transform($item->fresh(['client', 'project']))]);
    }

    public function remind(Request $request, $id)
    {
        $item = ComplianceItem::findOrFail($id);
        $this->authorize('remind', $item);
        [$reminder, $created] = DB::transaction(function () use ($request, $item): array {
            $reminder = Reminder::firstOrCreate(
                ['user_id' => $request->user()->id, 'source' => "compliance:{$item->id}"],
                ['title' => Str::limit($item->action_required . ' — ' . $item->matter, 250), 'description' => "{$item->jurisdiction} deadline",
                 'category' => 'Deadline', 'due_date' => $item->deadline, 'scope' => 'self']
            );
            if ($reminder->wasRecentlyCreated) $this->audit($request, 'reminder_create', $item, ['reminder_id' => $reminder->id]);
            return [$reminder, $reminder->wasRecentlyCreated];
        });
        return response()->json(['ok' => true, 'created' => $created, 'reminder_id' => $reminder->id]);
    }

    private function visibleQuery(Request $request): Builder
    {
        $query = ComplianceItem::query();
        if ($request->user()->isGalvanizer()) {
            $codes = $request->user()->galvanizerCircleCodes();
            $query->where(function (Builder $scope) use ($request, $codes): void {
                $scope->where('assignee_id', $request->user()->id)
                    ->orWhereHas('project', fn (Builder $projects) => $projects->whereIn('circle', $codes ?: ['__none__']));
            });
        }
        return $query;
    }

    private function applyFilters(Builder $query, Request $request, bool $includeStatus = true): void
    {
        if ($request->filled('search')) {
            $term = '%' . addcslashes($request->string('search')->trim()->toString(), '%_\\') . '%';
            $query->where(function (Builder $q) use ($term): void {
                $q->where('matter', 'ilike', $term)->orWhere('action_required', 'ilike', $term)
                    ->orWhereHas('client', fn (Builder $c) => $c->where('client_code', 'ilike', $term)->orWhere('legal_name', 'ilike', $term)->orWhere('company_name', 'ilike', $term))
                    ->orWhereHas('project', fn (Builder $p) => $p->where('project_code', 'ilike', $term)->orWhere('docket_number', 'ilike', $term)->orWhere('application_number', 'ilike', $term));
            });
        }
        $query->when($request->filled('client_id'), fn (Builder $q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->string('type')->toString()))
            ->when($request->filled('jurisdiction'), fn (Builder $q) => $q->where('jurisdiction', $request->string('jurisdiction')->toString()))
            ->when($request->filled('from_date'), fn (Builder $q) => $q->whereDate('deadline', '>=', $request->date('from_date')))
            ->when($request->filled('to_date'), fn (Builder $q) => $q->whereDate('deadline', '<=', $request->date('to_date')));
        if ($includeStatus && $request->filled('status')) {
            $today = Carbon::today();
            match ($request->string('status')->toString()) {
                'Critical' => $query->where('deadline', '<=', $today->copy()->addDays(30)),
                'At Risk' => $query->whereBetween('deadline', [$today->copy()->addDays(31), $today->copy()->addDays(75)]),
                'On Track' => $query->whereBetween('deadline', [$today->copy()->addDays(76), $today->copy()->addDays(150)]),
                'Compliant' => $query->where('deadline', '>', $today->copy()->addDays(150)), default => null,
            };
        }
    }

    private function transform(ComplianceItem $item): array
    {
        $daysLeft = (int) Carbon::today()->diffInDays($item->deadline, false);
        return ['id' => $item->id, 'matter' => $item->matter, 'type' => $item->type, 'jurisdiction' => $item->jurisdiction,
            'deadline' => $item->deadline->format('Y-m-d'), 'daysLeft' => $daysLeft, 'status' => $this->alertLevel($daysLeft),
            'action' => $item->action_required, 'assignee' => $item->assignee, 'assignee_id' => $item->assignee_id,
            'notes' => $item->notes ?? [], 'source_type' => $item->source_type, 'client' => $item->client, 'project' => $item->project];
    }

    private function alertLevel(int $daysLeft): string
    {
        return match (true) { $daysLeft <= 30 => 'Critical', $daysLeft <= 75 => 'At Risk', $daysLeft <= 150 => 'On Track', default => 'Compliant' };
    }

    private function audit(Request $request, string $action, ComplianceItem $item, array $metadata): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'action' => $action, 'subject_type' => 'ComplianceItem',
            'subject_id' => $item->id, 'metadata' => $metadata, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
    }
}
