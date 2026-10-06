<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\TaxSlabConfig;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Admin-editable income tax slabs (Settings → Payroll → Taxes) — these
 * change every Union Budget, so they live in the database rather than
 * code, same philosophy as Professional Tax. Drives IncomeTaxComputationService.
 */
class TaxSlabConfigController extends Controller
{
    private const MANAGE_ROLES = User::PAYROLL_ACCESS_ROLES;

    public function index(Request $request)
    {
        if (! in_array($request->user()->role, User::PAYROLL_ACCESS_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(TaxSlabConfig::orderByDesc('financial_year')->orderBy('regime')->get());
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $this->validatedPayload($request, true);
        $config = TaxSlabConfig::create($validated);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'create_tax_slab_config',
            'subject_type' => 'TaxSlabConfig', 'subject_id' => $config->id,
            'metadata' => ['financial_year' => $config->financial_year, 'regime' => $config->regime],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($config, 201);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $config = TaxSlabConfig::findOrFail($id);
        $validated = $this->validatedPayload($request, false);
        $config->update($validated);

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'update_tax_slab_config',
            'subject_type' => 'TaxSlabConfig', 'subject_id' => $config->id,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($config);
    }

    private function validatedPayload(Request $request, bool $requireIdentity): array
    {
        $rules = [
            'slabs_json' => 'required|array|min:1',
            'slabs_json.*.upto' => 'nullable|numeric|min:0',
            'slabs_json.*.rate' => 'required|numeric|min:0|max:100',
            'standard_deduction' => 'required|numeric|min:0',
            'rebate_87a_limit' => 'required|numeric|min:0',
            'rebate_87a_amount' => 'required|numeric|min:0',
            'cess_rate' => 'required|numeric|min:0|max:100',
        ];
        if ($requireIdentity) {
            $rules['financial_year'] = 'required|integer|min:2000|max:2100';
            $rules['regime'] = 'required|in:old,new';
        }

        return $request->validate($rules);
    }
}
