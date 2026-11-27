<?php

namespace App\Http\Middleware;

use App\Http\Controllers\McpController;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Claude connector tokens (ability mcp:read only) may call the read-only MCP endpoint and
 * nothing else — never the portal API, which includes create / edit / delete routes.
 */
class RestrictConnectorTokens
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        if ($bearer && ! $request->is('mcp') && ($token = PersonalAccessToken::findToken($bearer))) {
            $abilities = (array) $token->abilities;
            if (in_array(McpController::ABILITY, $abilities, true) && ! in_array('*', $abilities, true)) {
                return response()->json(['message' => 'This token is for the read-only Claude connector only.'], 403);
            }
            // A client API key reads the client API only.
            if (in_array(\App\Http\Controllers\ClientApiController::ABILITY, $abilities, true) && ! in_array('*', $abilities, true) && ! $request->is('api/client-api/*')) {
                return response()->json(['message' => 'This key is for the read-only client API only (/api/client-api/v1/...).'], 403);
            }
        }

        return $next($request);
    }
}
