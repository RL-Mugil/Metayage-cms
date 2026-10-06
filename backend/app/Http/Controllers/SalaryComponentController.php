<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\SalaryComponentValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The firm-wide salary structure template — one ordered list of earning
 * components (flat ₹, percentage-of, or the balancing remainder) applied
 * identically to every employee's single `salary` figure by PayrollService.
 * Read by any authenticated staff member (needed to render payslip columns —
 * this is just the template shape, never an individual's actual pay);
 * managed by director/accountant/system admin only, same tier as PayrollController.
 */
class SalaryComponentController extends Controller
{
    private const MANAGE_ROLES = User::PAYROLL_ACCESS_ROLES;

    public function index(Request $request)
    {
        return response()->json(SalaryComponent::orderBy('sequence')->get());
    }

    private function denyUnlessManager(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (! in_array($request->user()->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return null;
    }

    private function rules(bool $isUpdate, $ignoreId = null): array
    {
        return [
            'name' => 'required|string|max:120',
            'code' => 'required|string|max:40|regex:/^[A-Z0-9_]+$/|unique:salary_components,code' . ($ignoreId ? ",{$ignoreId}" : ''),
            'calc_type' => 'required|in:flat,percentage,remainder,employee_field',
            'category' => 'nullable|in:earning,ctc_addon',
            'value' => 'nullable|numeric|min:0',
            'percentage_of' => 'nullable|string|max:40',
            'sequence' => 'required|integer|min:1',
            'active' => 'nullable|boolean',
        ];
    }

    public function store(Request $request, SalaryComponentValidator $validator)
    {
        if ($deny = $this->denyUnlessManager($request)) {
            return $deny;
        }

        $validated = $request->validate($this->rules(false));
        $validated['code'] = strtoupper($validated['code']);
        $validated['active'] = $validated['active'] ?? true;
        $validator->assertValid(null, $validated);

        $row = DB::transaction(function () use ($request, $validated) {
            $row = SalaryComponent::create($validated);

            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'create_salary_component',
                'subject_type' => 'SalaryComponent', 'subject_id' => $row->id,
                'metadata' => $validated, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);

            return $row;
        });

        return response()->json($row, 201);
    }

    public function update(Request $request, $id, SalaryComponentValidator $validator)
    {
        if ($deny = $this->denyUnlessManager($request)) {
            return $deny;
        }

        $row = SalaryComponent::findOrFail($id);
        $validated = $request->validate($this->rules(true, $row->id));
        $validated['code'] = strtoupper($validated['code']);
        $validator->assertValid($row, $validated);

        DB::transaction(function () use ($request, $row, $validated) {
            $row->update($validated);

            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'update_salary_component',
                'subject_type' => 'SalaryComponent', 'subject_id' => $row->id,
                'metadata' => $validated, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
        });

        return response()->json($row->fresh());
    }

    public function destroy(Request $request, $id, SalaryComponentValidator $validator)
    {
        if ($deny = $this->denyUnlessManager($request)) {
            return $deny;
        }

        $row = SalaryComponent::findOrFail($id);
        $validator->assertSafeToDelete($row);

        DB::transaction(function () use ($request, $row) {
            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'delete_salary_component',
                'subject_type' => 'SalaryComponent', 'subject_id' => $row->id,
                'metadata' => ['code' => $row->code, 'name' => $row->name],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
            $row->delete();
        });

        return response()->json(['ok' => true]);
    }

    /** Persists a new display/execution order from a drag-reordered list of ids. */
    public function reorder(Request $request)
    {
        if ($deny = $this->denyUnlessManager($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:salary_components,id',
        ]);

        DB::transaction(function () use ($request, $validated) {
            foreach ($validated['ids'] as $index => $id) {
                SalaryComponent::whereKey($id)->update(['sequence' => $index + 1]);
            }

            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'reorder_salary_components',
                'subject_type' => 'SalaryComponent', 'subject_id' => null,
                'metadata' => ['order' => $validated['ids']], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
        });

        return response()->json(SalaryComponent::orderBy('sequence')->get());
    }
}
