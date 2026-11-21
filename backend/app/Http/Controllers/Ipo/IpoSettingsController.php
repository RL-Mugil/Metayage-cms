<?php

namespace App\Http\Controllers\Ipo;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Ipo\Form1DataBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Settings → IPO Forms: the firm's address for service in India (Form 1 section 7) and
 * each patent agent's IN/PA number and mobile (section 6). Admins and partners only.
 */
class IpoSettingsController extends Controller
{
    private const ADMIN_ROLES = ['super_admin', 'partner'];

    public function show(Request $request): JsonResponse
    {
        $this->denyUnlessAdmin($request);
        $rows = DB::table('system_settings')->where('key', 'like', 'ipo_service_%')->pluck('value', 'key');

        return response()->json([
            'service' => collect(Form1DataBuilder::SERVICE_KEYS)
                ->mapWithKeys(fn (string $key) => [$key => $rows['ipo_service_'.$key] ?? ''])->all(),
            'staff'   => User::whereIn('role', User::STAFF_ROLES)->where('status', 'Active')->orderBy('name')->get()
                ->map(fn (User $user) => [
                    'id' => $user->id, 'name' => $user->name, 'role' => $user->role,
                    'patent_agent_no' => $user->patent_agent_no, 'mobile' => $user->mobile,
                ])->all(),
        ]);
    }

    public function updateService(Request $request): JsonResponse
    {
        $this->denyUnlessAdmin($request);
        $validated = $request->validate([
            'name'    => 'nullable|string|max:255',
            'address' => 'nullable|string|max:1000',
            'phone'   => 'nullable|string|max:50',
            'mobile'  => 'nullable|string|max:50',
            'fax'     => 'nullable|string|max:50',
            'email'   => 'nullable|email|max:255',
        ]);

        DB::transaction(function () use ($request, $validated) {
            $now = now();
            foreach ($validated as $key => $value) {
                DB::table('system_settings')->updateOrInsert(
                    ['key' => 'ipo_service_'.$key],
                    ['value' => (string) ($value ?? ''), 'updated_at' => $now],
                );
            }
            $this->audit($request, 'ipo_service_address_updated', 'SystemSetting', 0, ['fields' => array_keys($validated)]);
        });

        return $this->show($request);
    }

    public function updateAgent(Request $request, $userId): JsonResponse
    {
        $this->denyUnlessAdmin($request);
        $user = User::whereIn('role', User::STAFF_ROLES)->findOrFail($userId);
        // Normalise first so the uniqueness check sees the stored form (upper case, trimmed).
        $number = trim((string) $request->input('patent_agent_no', ''));
        $request->merge(['patent_agent_no' => $number === '' ? null : strtoupper($number)]);
        $validated = $request->validate([
            'patent_agent_no' => ['nullable', 'string', 'max:30', Rule::unique('users', 'patent_agent_no')->ignore($user->id)],
            'mobile'          => 'nullable|string|max:20',
        ]);

        DB::transaction(function () use ($request, $user, $validated) {
            $user->update($validated);
            $this->audit($request, 'patent_agent_updated', 'User', $user->id, ['patent_agent_no' => $validated['patent_agent_no']]);
        });

        return response()->json(['ok' => true]);
    }

    private function denyUnlessAdmin(Request $request): void
    {
        abort_unless(in_array($request->user()->role, self::ADMIN_ROLES, true), 403, 'Forbidden');
    }

    private function audit(Request $request, string $action, string $type, int $subjectId, array $metadata): void
    {
        AuditLog::create([
            'user_id'      => $request->user()->id,
            'action'       => $action,
            'subject_type' => $type,
            'subject_id'   => $subjectId,
            'metadata'     => $metadata,
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);
    }
}
