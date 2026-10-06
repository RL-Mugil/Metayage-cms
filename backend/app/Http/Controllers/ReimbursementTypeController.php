<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ReimbursementType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin-configured catalog of reimbursement categories (Travel, Internet, ...),
 * each optionally capped per calendar month. Read by any authenticated staff
 * member (employees need the list to submit a claim); managed by HR/super_admin.
 */
class ReimbursementTypeController extends Controller
{
    private const MANAGE_ROLES = ['super_admin', 'hr'];

    public function index(Request $request)
    {
        $query = ReimbursementType::orderBy('name');
        if (! $request->boolean('include_inactive')) {
            $query->where('active', true);
        }

        return response()->json($query->get());
    }

    private function denyUnlessManager(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (! in_array($request->user()->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return null;
    }

    private function rules($ignoreId = null): array
    {
        return [
            'name' => 'required|string|max:120',
            'code' => 'required|string|max:40|regex:/^[A-Z0-9_]+$/|unique:reimbursement_types,code' . ($ignoreId ? ",{$ignoreId}" : ''),
            'monthly_cap' => 'nullable|numeric|min:0',
            'active' => 'nullable|boolean',
        ];
    }

    public function store(Request $request)
    {
        if ($deny = $this->denyUnlessManager($request)) {
            return $deny;
        }

        $validated = $request->validate($this->rules());
        $validated['code'] = strtoupper($validated['code']);
        $validated['active'] = $validated['active'] ?? true;

        $row = DB::transaction(function () use ($request, $validated) {
            $row = ReimbursementType::create($validated);

            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'create_reimbursement_type',
                'subject_type' => 'ReimbursementType', 'subject_id' => $row->id,
                'metadata' => $validated, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);

            return $row;
        });

        return response()->json($row, 201);
    }

    public function update(Request $request, $id)
    {
        if ($deny = $this->denyUnlessManager($request)) {
            return $deny;
        }

        $row = ReimbursementType::findOrFail($id);
        $validated = $request->validate($this->rules($row->id));
        $validated['code'] = strtoupper($validated['code']);

        DB::transaction(function () use ($request, $row, $validated) {
            $row->update($validated);

            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'update_reimbursement_type',
                'subject_type' => 'ReimbursementType', 'subject_id' => $row->id,
                'metadata' => $validated, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
        });

        return response()->json($row->fresh());
    }

    public function destroy(Request $request, $id)
    {
        if ($deny = $this->denyUnlessManager($request)) {
            return $deny;
        }

        $row = ReimbursementType::findOrFail($id);

        if ($row->claims()->exists()) {
            return response()->json(['message' => 'This type has existing reimbursement claims — deactivate it instead of deleting.'], 422);
        }

        DB::transaction(function () use ($request, $row) {
            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'delete_reimbursement_type',
                'subject_type' => 'ReimbursementType', 'subject_id' => $row->id,
                'metadata' => ['code' => $row->code, 'name' => $row->name],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
            $row->delete();
        });

        return response()->json(['ok' => true]);
    }
}
