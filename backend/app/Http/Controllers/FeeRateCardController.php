<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FeeRateCard;
use Illuminate\Http\Request;

/**
 * Government + professional fee rate card — read by staff (Financial.tsx's
 * quote/invoice auto-fill, RenewalActionController's per-year renewal math),
 * managed by super_admin only (Settings > Finance).
 */
class FeeRateCardController extends Controller
{
    public function index(Request $request)
    {
        $query = FeeRateCard::query()->orderBy('jurisdiction')->orderBy('service_code')->orderBy('year_from');
        if ($request->filled('jurisdiction')) {
            $query->where('jurisdiction', strtoupper($request->get('jurisdiction')));
        }
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return response()->json($query->get());
    }

    private function denyUnlessSuperAdmin(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }

    private function rules(): array
    {
        return [
            'jurisdiction' => 'required|string|max:5',
            'service_code' => 'required|string|max:10',
            'entity_tier' => 'nullable|in:discounted,standard',
            'year_from' => 'nullable|numeric|min:0|max:99',
            'year_to' => 'nullable|numeric|min:0|max:99',
            'validation_country' => 'nullable|string|max:50',
            'govt_fee_amount' => 'nullable|numeric|min:0',
            'govt_fee_currency' => 'nullable|string|max:6',
            'professional_fee_amount' => 'nullable|numeric|min:0',
            'professional_fee_currency' => 'nullable|string|max:6',
            'professional_fee_max_amount' => 'nullable|numeric|min:0',
            'professional_fee_charge_basis' => 'nullable|in:per_unit,flat_per_transaction',
            'notes' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function store(Request $request)
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate($this->rules());
        $row = FeeRateCard::create($validated);

        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => 'create_fee_rate_card',
            'subject_type' => 'FeeRateCard', 'subject_id' => $row->id,
            'metadata' => $validated, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($row, 201);
    }

    public function update(Request $request, $id)
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        $row = FeeRateCard::findOrFail($id);
        $validated = $request->validate($this->rules());
        $row->update($validated);

        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => 'update_fee_rate_card',
            'subject_type' => 'FeeRateCard', 'subject_id' => $row->id,
            'metadata' => $validated, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($row->fresh());
    }

    public function destroy(Request $request, $id)
    {
        if ($deny = $this->denyUnlessSuperAdmin($request)) {
            return $deny;
        }

        $row = FeeRateCard::findOrFail($id);
        $row->delete();

        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => 'delete_fee_rate_card',
            'subject_type' => 'FeeRateCard', 'subject_id' => $id,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }
}
