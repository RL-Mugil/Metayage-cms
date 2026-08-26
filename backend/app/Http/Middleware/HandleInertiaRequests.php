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
                'feature_link_predecessor'    => ($rows['feature_link_predecessor']    ?? 'true') === 'true',
                'feature_legacy_case'         => ($rows['feature_legacy_case']         ?? 'true') === 'true',
                'feature_existing_client'     => ($rows['feature_existing_client']     ?? 'true') === 'true',
                'feature_lock_code_dropdowns' => ($rows['feature_lock_code_dropdowns'] ?? 'true') === 'true',
                'dropdown_service_codes'      => $decode('dropdown_service_codes',  []),
                'dropdown_country_codes'      => $decode('dropdown_country_codes',  []),
                'renewal_fee_rates'           => $decode('renewal_fee_rates', ['government_fee' => 0, 'professional_fee' => 0, 'currency' => 'INR']),
            ];
        });
    }
}
