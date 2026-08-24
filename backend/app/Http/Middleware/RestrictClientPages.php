<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Server-side page gating for client portal users (and, by the same
 * mechanism, the inventor role — also an external, restricted-access login).
 * Clients typing internal URLs (/clients, /hrms/payroll, …) are sent back
 * to their dashboard instead of rendering an internal page shell.
 *
 * client/client_admin get the full case-visibility page set; client_finance
 * (billing-only login) gets a much smaller allowlist — no case, kanban,
 * or approval pages, since it has no visibility into those (see
 * RolePermissions::forRole('client_finance')). inventor gets the dashboard,
 * their own case pages (via the /projects/ prefix, ACL'd per-case by
 * ProjectPolicy::view()'s inventor branch), and /approvals — scoped
 * server-side to technical-kind approvals for the client(s) of their own
 * projects (ApprovalController::inventorIndex()/resolve()), since an
 * inventor isn't tied to one client the way a portal login is.
 */
class RestrictClientPages
{
    /** Page paths a portal user may open (exact match), keyed by role. Same list reused for client + client_admin. */
    private const ALLOWED = [
        'client' => [
            '/', '/patent-portfolio', '/patent-lifecycle', '/projects', '/tasks',
            '/kanban', '/calendar', '/team', '/documents', '/discussions',
            '/approvals', '/financial', '/pending-payments', '/feedback', '/notifications', '/settings', '/logout',
        ],
        'client_admin' => [
            '/', '/patent-portfolio', '/patent-lifecycle', '/projects', '/tasks',
            '/kanban', '/calendar', '/team', '/documents', '/discussions',
            '/approvals', '/financial', '/pending-payments', '/feedback', '/notifications', '/settings', '/logout',
            '/portal-users',
        ],
        'client_finance' => [
            '/', '/financial', '/pending-payments', '/documents', '/notifications', '/settings', '/logout',
        ],
        'inventor' => [
            '/', '/approvals', '/notifications', '/settings', '/logout',
        ],
    ];

    /** Prefixes: any path starting with these is allowed (e.g. /projects/42), keyed by role. */
    private const ALLOWED_PREFIXES = [
        'client' => ['/projects/', '/tasks/', '/documents/', '/discussions/'],
        'client_admin' => ['/projects/', '/tasks/', '/documents/', '/discussions/'],
        'client_finance' => ['/documents/'],
        'inventor' => ['/projects/'],
    ];

    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if ($user && ($user->isClientRole() || $user->isInventor())) {
            $path = '/' . ltrim($request->path(), '/');
            $allowedPaths = self::ALLOWED[$user->role] ?? self::ALLOWED['client'];
            $allowedPrefixes = self::ALLOWED_PREFIXES[$user->role] ?? self::ALLOWED_PREFIXES['client'];

            $allowed = in_array($path, $allowedPaths, true);

            if (! $allowed) {
                foreach ($allowedPrefixes as $prefix) {
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
