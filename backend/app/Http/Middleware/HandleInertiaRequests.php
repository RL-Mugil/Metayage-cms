<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id'         => $user->id,
                    'name'       => $user->name,
                    'email'      => $user->email,
                    'role'       => $user->role,
                    'status'     => $user->status,
                    'avatar_url' => $user->avatar_url,
                    'unread_notifications' => fn () => DB::table('ip_notifications')
                        ->where('user_id', $user->id)
                        ->whereNull('read_at')
                        ->count(),
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error'   => fn () => $request->session()->get('error'),
            ],
            'systemSettings' => fn () => $this->loadSystemSettings(),
            // Public key only — safe on every page including the guest login screen. The
            // matching secret_key never leaves the server (see AuthController::verifyTurnstile()).
            'turnstileSiteKey' => config('services.turnstile.site_key'),
        ];
    }

    private function loadSystemSettings(): array
    {
        return Cache::remember('system_settings_shared', 300, function () {
            $rows = DB::table('system_settings')->pluck('value', 'key');

            $decode = fn ($key, $default) => isset($rows[$key])
                ? (json_decode($rows[$key], true) ?? $rows[$key])
                : $default;

            return [
                'env_badge'                   => \App\Services\SiteSettings::badge(),
                'feature_link_predecessor'    => ($rows['feature_link_predecessor']    ?? 'true') === 'true',
                'feature_legacy_case'         => ($rows['feature_legacy_case']         ?? 'true') === 'true',
                'feature_existing_client'     => ($rows['feature_existing_client']     ?? 'true') === 'true',
                'feature_lock_code_dropdowns' => ($rows['feature_lock_code_dropdowns'] ?? 'true') === 'true',
                'dropdown_service_codes'      => $decode('dropdown_service_codes',  []),
                'dropdown_country_codes'      => $decode('dropdown_country_codes',  []),
                'renewal_fee_rates'           => $decode('renewal_fee_rates', ['government_fee' => 0, 'professional_fee' => 0, 'currency' => 'INR']),
                'project_tabs'                => $decode('project_tabs', []),
                // Display/edit only — PayrollService reads these keys directly from
                // the DB (see currentProfessionalTax()), never this cached prop,
                // since payroll generation runs in a queue worker. Karnataka PT
                // slab: gross ≤ threshold → Nil, else monthly_amount (300 in Feb).
                'payroll_pt_nil_threshold'    => isset($rows['payroll_pt_nil_threshold']) ? (float) $rows['payroll_pt_nil_threshold'] : 25000.0,
                'payroll_pt_monthly_amount'   => isset($rows['payroll_pt_monthly_amount']) ? (float) $rows['payroll_pt_monthly_amount'] : 200.0,
                'payroll_pt_february_amount'  => isset($rows['payroll_pt_february_amount']) ? (float) $rows['payroll_pt_february_amount'] : 300.0,
                // Display/edit only — PayrollService/GeneratePayrollJob always read
                // PF/ESI toggles directly from the DB (currentPfEnabled()/currentEsiEnabled()),
                // never this cached prop, for the same queue-worker-cache reason as PT.
                'payroll_pf_enabled'          => ($rows['payroll_pf_enabled'] ?? 'true') === 'true',
                'payroll_esi_enabled'         => ($rows['payroll_esi_enabled'] ?? 'true') === 'true',
                'payroll_company_name'        => $rows['payroll_company_name'] ?? '',
                'payroll_company_address'     => $rows['payroll_company_address'] ?? '',
                'payroll_company_logo_url'    => $rows['payroll_company_logo_url'] ?? '',
                'payroll_company_tan'         => $rows['payroll_company_tan'] ?? '',
                'payroll_pay_day'             => isset($rows['payroll_pay_day']) ? (int) $rows['payroll_pay_day'] : 1,
                'payroll_signatory_name'         => $rows['payroll_signatory_name'] ?? '',
                'payroll_signatory_designation'  => $rows['payroll_signatory_designation'] ?? '',
            ];
        });
    }
}
