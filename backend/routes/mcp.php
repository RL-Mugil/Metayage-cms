<?php

use App\Http\Controllers\McpController;
use App\Http\Controllers\OAuthController;
use Illuminate\Support\Facades\Route;

/*
| Claude connector (MCP) — registered with the api middleware group but no /api prefix
| (see bootstrap/app.php). Read-only: the MCP endpoint only lists and runs App\Ai tools.
*/

Route::get('/.well-known/oauth-protected-resource', [OAuthController::class, 'protectedResource']);
Route::get('/.well-known/oauth-protected-resource/mcp', [OAuthController::class, 'protectedResource']);
Route::get('/.well-known/oauth-authorization-server', [OAuthController::class, 'authorizationServer']);
Route::get('/.well-known/oauth-authorization-server/mcp', [OAuthController::class, 'authorizationServer']);

Route::post('/oauth/register', [OAuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/oauth/token', [OAuthController::class, 'token'])->middleware('throttle:30,1');
Route::post('/oauth/revoke', [OAuthController::class, 'revoke'])->middleware('throttle:30,1');

Route::post('/mcp', [McpController::class, 'handle'])->middleware('throttle:120,1');

// Files Claude produced (reports, payslips, calendars): 30-minute secret links, no sign-in.
Route::get('/files/claude/{token}', [\App\Http\Controllers\ClaudeFileController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1');
Route::get('/mcp', [McpController::class, 'get']);
