<?php

namespace App\Http\Controllers;

use App\Ai\ToolRegistry;
use App\Models\AuditLog;
use App\Support\ClientAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Client API keys (a client's login with "Claude, API & integrations"): personal keys with the
 * single ability client:read, for a small read-only API about the client's own data, the same
 * answers the AI Assistant gets (App\Ai\ClientTools). Every call is in the client's audit log.
 */
class ClientApiController extends Controller
{
    public const ABILITY = 'client:read';

    private const ENDPOINT_TOOL = ['cases' => 'my_cases', 'deadlines' => 'my_deadlines', 'billing' => 'my_billing', 'approvals' => 'my_approvals'];

    /** GET /api/client-api/v1/{endpoint}: read-only, with a client API key. */
    public function read(Request $request, string $endpoint, ToolRegistry $registry): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        abort_unless($user && $user->isClientRole() && $token instanceof PersonalAccessToken && $token->can(self::ABILITY), 403, 'Use a client API key.');
        abort_unless(ClientAccess::can($user, 'claude_api'), 403, 'Your portal access does not include the API.');
        $tool = self::ENDPOINT_TOOL[$endpoint] ?? abort(404, 'Unknown endpoint. Use cases, deadlines, billing or approvals.');
        $args = array_intersect_key($request->query(), array_flip(['search', 'open_only', 'days', 'limit', 'pending_only']));
        foreach (['open_only', 'pending_only'] as $flag) {
            if (isset($args[$flag])) {
                $args[$flag] = filter_var($args[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }
        $result = $registry->call($user, $tool, $args, 'api');
        abort_if(isset($result['error']), 403, (string) ($result['error'] ?? ''));

        return response()->json($result);
    }

    /** The signed-in client user's own API keys. */
    public function keys(Request $request): JsonResponse
    {
        $user = $this->keyOwner($request);

        return response()->json($user->tokens()->where('abilities', 'like', '%'.self::ABILITY.'%')->latest('id')->get()
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'created_at' => $t->created_at?->toIso8601String(),
                'last_used_at' => $t->last_used_at?->toIso8601String(), 'expires_at' => $t->expires_at?->toIso8601String()])->values());
    }

    public function createKey(Request $request): JsonResponse
    {
        $user = $this->keyOwner($request);
        $data = $request->validate(['name' => 'required|string|max:60']);
        abort_if($user->tokens()->where('abilities', 'like', '%'.self::ABILITY.'%')->count() >= 10, 422, 'At most 10 API keys per user. Revoke an old one first.');
        $token = $user->createToken('API: '.$data['name'], [self::ABILITY], now()->addYear());
        AuditLog::create(['user_id' => $user->id, 'action' => 'api_key_created', 'subject_type' => 'User', 'subject_id' => $user->id,
            'metadata' => ['name' => $data['name'], 'token_id' => $token->accessToken->id], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        // The key is shown once; only its hash is kept.
        return response()->json(['id' => $token->accessToken->id, 'key' => $token->plainTextToken, 'expires_at' => $token->accessToken->expires_at?->toIso8601String()], 201);
    }

    public function revokeKey(Request $request, int $id): JsonResponse
    {
        $user = $this->keyOwner($request);
        $token = $user->tokens()->where('abilities', 'like', '%'.self::ABILITY.'%')->findOrFail($id);
        AuditLog::create(['user_id' => $user->id, 'action' => 'api_key_revoked', 'subject_type' => 'User', 'subject_id' => $user->id,
            'metadata' => ['name' => $token->name, 'token_id' => $token->id], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
        $token->delete();

        return response()->json(['ok' => true]);
    }

    private function keyOwner(Request $request): \App\Models\User
    {
        $user = $request->user();
        abort_unless($user->isClientRole() && ClientAccess::can($user, 'claude_api'), 403, 'Your portal access does not include API keys.');

        return $user;
    }
}
