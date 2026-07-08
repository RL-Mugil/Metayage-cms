<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Server-side page gating for client portal users.
 * Clients typing internal URLs (/clients, /hrms/payroll, …) are sent back
 * to their dashboard instead of rendering an internal page shell.
 */
class RestrictClientPages
{
    /** Page paths a portal user may open. */
    private const ALLOWED = [
        '/',
        '/patent-portfolio',
        '/documents',
        '/discussions',
        '/approvals',
        '/financial',
        '/feedback',
        '/notifications',
        '/settings',
        '/logout',
    ];

    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if ($user && $user->isClientRole()) {
            $path = '/' . ltrim($request->path(), '/');

            $allowed = in_array($path, self::ALLOWED, true)
                || ($path === '/portal-users' && $user->role === 'client_admin');

            if (! $allowed) {
                return redirect('/');
            }
        }
        return $next($request);
    }
}
