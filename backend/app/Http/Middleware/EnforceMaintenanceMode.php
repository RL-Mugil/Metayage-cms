<?php

namespace App\Http\Middleware;

use App\Services\SiteSettings;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * While maintenance mode is on, every signed-in user except a system admin (super_admin) gets
 * the maintenance page instead of the portal. Sign-in, sign-out, password reset, health checks,
 * webhooks and the public marketing endpoints keep working, so logging in works as usual.
 */
class EnforceMaintenanceMode
{
    private const ALWAYS_ALLOWED = [
        'login', 'logout', 'forgot-password', 'reset-password', 'reset-password/*', 'up',
        'api/public/*', 'api/webhooks/*', 'api/mobile/auth/login',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $state = SiteSettings::maintenance();
        if (! $state['enabled'] || $request->is(...self::ALWAYS_ALLOWED)) {
            return $next($request);
        }

        $user = $request->user() ?? $request->user('sanctum');
        // Guests fall through to the normal flow (redirect to /login or 401).
        if (! $user || $user->role === 'super_admin') {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['maintenance' => true, 'message' => $state['message']], 503, ['Retry-After' => '300']);
        }

        // An Inertia (XHR) visit can't render the Blade page; force a full page load instead.
        if ($request->header('X-Inertia')) {
            return Inertia::location($request->fullUrl());
        }

        return response()->view('maintenance', ['state' => $state, 'userName' => $user->name], 503, ['Retry-After' => '300']);
    }
}
