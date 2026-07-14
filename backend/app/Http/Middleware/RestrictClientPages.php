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
    /** Page paths a portal user may open (exact match). */
    private const ALLOWED = [
        '/',
        '/patent-portfolio',
        '/patent-lifecycle',
        '/projects',
        '/tasks',
        '/kanban',
        '/calendar',
        '/team',
        '/documents',
        '/discussions',
        '/approvals',
        '/financial',
        '/feedback',
        '/notifications',
        '/settings',
        '/logout',
    ];

    /** Prefixes: any path starting with these is allowed (e.g. /projects/42). */
    private const ALLOWED_PREFIXES = [
        '/projects/',
        '/tasks/',
        '/documents/',
        '/discussions/',
    ];

    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if ($user && $user->isClientRole()) {
            $path = '/' . ltrim($request->path(), '/');

            $allowed = in_array($path, self::ALLOWED, true)
                || ($path === '/portal-users' && $user->role === 'client_admin');

            if (! $allowed) {
                foreach (self::ALLOWED_PREFIXES as $prefix) {
                    if (str_starts_with($path, $prefix)) {
                        $allowed = true;
                        break;
                    }
                }
            }

            if (! $allowed) {
                return redirect('/');
            }
        }
        return $next($request);
    }
}
