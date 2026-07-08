<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;

class EnsureClientPortalEnabled
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if ($user && $user->isClientRole()) {
            $client = Client::forUser($user);
            if (! $client || ! $client->portal_enabled) {
                return response()->json(['message' => 'Portal access is disabled. Contact your account manager.'], 403);
            }
            // Make the resolved client available downstream so controllers
            // don't re-query it for scoping.
            $request->attributes->set('portal_client', $client);
        }
        return $next($request);
    }
}
