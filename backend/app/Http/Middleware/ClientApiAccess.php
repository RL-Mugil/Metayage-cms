<?php

namespace App\Http\Middleware;

use App\Support\ClientAccess;
use Closure;
use Illuminate\Http\Request;

/**
 * API side of a client login's access checkboxes: calls for an area the login's designation
 * does not include are refused (the pages are already hidden and gated). Firm staff and
 * inventors are not affected.
 */
class ClientApiAccess
{
    /** First match wins: more specific paths first. */
    private const PERMISSION = [
        '#^api/pending-payments/\d+/approve-quote#' => 'approvals',
        '#^api/approvals#' => 'approvals',
        '#^api/(my-billing|pending-payments|patent-invoices|financial)(/|$)#' => 'billing',
        '#^api/clients/\d+/statement#' => 'billing',
        '#^api/(discussions|dm|threads)(/|$)#' => 'discussions',
        '#^api/documents(/|$)#' => 'documents',
        '#^api/tasks(/|$)#' => 'tasks',
        '#^api/reports(/|$)#' => 'reports',
        '#^api/my-portal/(users|designations)#' => 'manage_users',
        '#^api/my-portal/audit#' => 'audit_log',
        '#^api/my-portal/api-keys#' => 'claude_api',
        '#^api/client-api/#' => 'claude_api',
        '#^api/oauth/connections#' => 'claude_api',
        '#^api/ai(/|$)#' => 'ai',
    ];

    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if ($user && $user->isClientRole()) {
            $path = ltrim($request->path(), '/');
            foreach (self::PERMISSION as $pattern => $permission) {
                if (preg_match($pattern, $path)) {
                    if (! ClientAccess::can($user, $permission)) {
                        return response()->json(['message' => 'Your portal access does not include this. Ask your portal admin.'], 403);
                    }
                    break;
                }
            }
        }

        return $next($request);
    }
}
