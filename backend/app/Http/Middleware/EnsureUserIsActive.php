<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if ($user && $user->status !== 'Active') {
            // Revoke the token so the next request also fails fast without a DB status check.
            $user->currentAccessToken()?->delete();
            return response()->json(['message' => 'Your account has been suspended. Contact your administrator.'], 403);
        }
        return $next($request);
    }
}
