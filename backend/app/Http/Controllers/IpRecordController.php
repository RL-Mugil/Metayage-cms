<?php

namespace App\Http\Controllers;

use App\Http\PaginationHelper;
use App\Http\Requests\StoreIpRecordRequest;
use App\Http\Resources\IpRecordResource;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Firm;
use App\Models\IpRecord;
use App\Models\PatentApplication;
use App\Models\TrademarkApplication;
use App\Models\User;
use App\Support\FirmContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IpRecordController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', IpRecord::class);
        $query = IpRecord::with([
            'client:id,client_code,company_name,legal_name',
            'responsibleUser:id,name',
            'backupUser:id,name',
            'projects:id,ip_record_id,docket_number,project_name,status',
        ])
            ->orderBy('record_code');
        if ($request->user()->isClientRole()) {
            $query->whereHas('client', fn ($client) => $client->visibleToUser($request->user()));
        }

        foreach (['record_type', 'jurisdiction', 'legal_status', 'responsible_user_id'] as $filter) {
            if ($request->filled($filter)) $query->where($filter, $request->input($filter));
        }
        if ($request->filled('search')) {
            $like = '%'.strtolower(trim((string) $request->input('search'))).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(record_code) LIKE ?', [$like])
                ->orWhereRaw('LOWER(title) LIKE ?', [$like])
                ->orWhereRaw('LOWER(client_reference) LIKE ?', [$like])
                ->orWhereHas('projects', fn ($project) => $project->whereRaw('LOWER(docket_number) LIKE ?', [$like])));
        }

        $result = PaginationHelper::paginate($query, $request);
        $result['data'] = IpRecordResource::collection($result['data'])->resolve($request);
        return response()->json($result);
    }

    public function show(Request $request, IpRecord $ipRecord): IpRecordResource
    {
        $this->authorize('view', $ipRecord);
        return new IpRecordResource($ipRecord->load([
            'client:id,client_code,company_name,legal_name', 'responsibleUser:id,name', 'backupUser:id,name',
            'patentApplication', 'trademarkApplication', 'projects:id,ip_record_id,project_code,docket_number,project_name,service_code,status,hard_deadline',
        ]));
    }

    public function store(StoreIpRecordRequest $request)
    {
        $validated = $request->validated();
        $firmId = app(FirmContext::class)->idOrSingleActiveFirm();
        Client::findOrFail($validated['client_id']);
        foreach (['responsible_user_id', 'backup_user_id'] as $userField) {
            if (! empty($validated[$userField])) {
                $candidate = User::findOrFail($validated[$userField]);
                abort_unless($candidate->firms()->whereKey($firmId)->exists() && ! $candidate->isClientRole(), 422, "{$userField} must reference a staff member of the active firm.");
            }
        }

        $record = DB::transaction(function () use ($request, $validated, $firmId): IpRecord {
            Firm::whereKey($firmId)->lockForUpdate()->firstOrFail();
            $year = now()->year;
            $last = IpRecord::withTrashed()->where('record_code', 'like', "IPR-{$year}-%")
                ->orderByDesc('record_code')->lockForUpdate()->value('record_code');
            $sequence = $last ? ((int) substr($last, -5)) + 1 : 1;

            $record = IpRecord::create([
                ...collect($validated)->except(['patent', 'trademark'])->all(),
                'record_code' => sprintf('IPR-%d-%05d', $year, $sequence),
                'jurisdiction' => strtoupper($validated['jurisdiction']),
            ]);

            if ($record->record_type === 'Patent') {
                PatentApplication::create(($validated['patent'] ?? []) + [
                    'ip_record_id' => $record->id, 'client_id' => $record->client_id,
                    'title' => $record->title, 'legal_status' => $record->legal_status,
                    'jurisdiction' => $record->jurisdiction,
                ]);
            } else {
                TrademarkApplication::create(($validated['trademark'] ?? []) + [
                    'ip_record_id' => $record->id, 'mark_text' => $validated['trademark']['mark_text'] ?? $record->title,
                ]);
            }

            AuditLog::create(['user_id' => $request->user()->id, 'action' => 'create', 'subject_type' => 'IpRecord',
                'subject_id' => $record->id, 'metadata' => ['record_code' => $record->record_code, 'record_type' => $record->record_type],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
            return $record;
        });

        return (new IpRecordResource($record->load('client')))->response()->setStatusCode(201);
    }
}
