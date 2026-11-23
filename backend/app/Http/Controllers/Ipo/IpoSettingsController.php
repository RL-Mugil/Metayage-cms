<?php

namespace App\Http\Controllers\Ipo;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\Ipo\Form1DataBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings → IPO Forms: the firm's address for service in India (Form 1 section 7), and
 * the list of Registered Patent Agents (section 6), who are marked in HRMS → Employees.
 * Admins and partners only.
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
            // Employees marked as Registered Patent Agent in HRMS → Employees (read-only here).
            'agents'  => User::whereNotNull('patent_agent_no')->where('patent_agent_no', '!=', '')
                ->where('status', 'Active')->orderBy('name')->get()
                ->map(function (User $user) {
                    $employee = Employee::with('designation:id,title')->where('user_id', $user->id)->first();

                    return [
                        'id'              => $user->id,
                        'name'            => $user->name,
                        'patent_agent_no' => $user->patent_agent_no,
                        'mobile'          => Form1DataBuilder::agentMobile($user),
                        'employee_code'   => $employee?->employee_code,
                        'designation'     => $employee?->designation?->title,
                        'has_signature'   => filled($user->patent_agent_signature_path),
                    ];
                })->all(),
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
