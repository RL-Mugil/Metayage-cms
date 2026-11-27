<?php

namespace App\Http\Controllers;

use App\Ai\Keys;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * OAuth 2.1 authorization server for the Claude connector (claude.ai, Claude Desktop):
 * metadata, dynamic client registration, the consent page, and the token endpoint with PKCE.
 * Access tokens are Sanctum tokens limited to the mcp:read ability (one hour); refresh tokens
 * rotate on every use and last 30 days. All of it grants read-only access only.
 */
class OAuthController extends Controller
{
    private const ACCESS_TTL = 3600;

    private const REFRESH_DAYS = 30;

    /** Hosted Claude callback addresses; Claude Desktop / Code use a local loopback address. */
    private const ALLOWED_REDIRECTS = ['https://claude.ai/api/mcp/auth_callback', 'https://claude.com/api/mcp/auth_callback'];

    private function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public function protectedResource(): JsonResponse
    {
        return response()->json([
            'resource' => $this->base().'/mcp',
            'authorization_servers' => [$this->base()],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => [McpController::ABILITY],
            'resource_name' => 'MyIPStrategy portal (read-only)',
        ]);
    }

    public function authorizationServer(): JsonResponse
    {
        $b = $this->base();

        return response()->json([
            'issuer' => $b,
            'authorization_endpoint' => $b.'/oauth/authorize',
            'token_endpoint' => $b.'/oauth/token',
            'registration_endpoint' => $b.'/oauth/register',
            'revocation_endpoint' => $b.'/oauth/revoke',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => [McpController::ABILITY],
        ]);
    }

    public static function redirectAllowed(string $uri): bool
    {
        if (in_array($uri, self::ALLOWED_REDIRECTS, true)) {
            return true;
        }
        $p = parse_url($uri);

        return is_array($p) && in_array($p['scheme'] ?? '', ['http', 'https'], true)
            && in_array(strtolower($p['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]', '::1'], true)
            && ! isset($p['user']) && ! isset($p['pass']) && ! isset($p['fragment']);
    }

    /** RFC 7591 dynamic client registration (public clients only). */
    public function register(Request $request): JsonResponse
    {
        $uris = $request->input('redirect_uris');
        if (! is_array($uris) || $uris === [] || count($uris) > 10) {
            return response()->json(['error' => 'invalid_redirect_uri', 'error_description' => 'redirect_uris is required.'], 400);
        }
        foreach ($uris as $uri) {
            if (! is_string($uri) || mb_strlen($uri) > 500 || ! self::redirectAllowed($uri)) {
                return response()->json(['error' => 'invalid_redirect_uri', 'error_description' => 'Only Claude (claude.ai / Claude Desktop) may connect to this portal.'], 400);
            }
        }
        $name = mb_substr(trim((string) $request->input('client_name', 'Claude')), 0, 200) ?: 'Claude';
        $clientId = 'mcp_'.Str::random(40);
        DB::table('oauth_clients')->insert(['client_id' => $clientId, 'name' => $name, 'redirect_uris' => json_encode(array_values($uris)), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json([
            'client_id' => $clientId, 'client_name' => $name, 'redirect_uris' => array_values($uris),
            'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none', 'client_id_issued_at' => now()->timestamp,
        ], 201);
    }

    /** @return array{0: ?object, 1: ?string} the client and an error message */
    private function checkRequest(array $q): array
    {
        $client = DB::table('oauth_clients')->where('client_id', (string) ($q['client_id'] ?? ''))->whereNull('revoked_at')->first();
        if (! $client) {
            return [null, 'This connection request is not recognised. Start again from Claude.'];
        }
        if (! in_array((string) ($q['redirect_uri'] ?? ''), (array) json_decode($client->redirect_uris, true), true)) {
            return [null, 'The return address does not match this connection. Start again from Claude.'];
        }
        if (($q['response_type'] ?? '') !== 'code' || ($q['code_challenge_method'] ?? '') !== 'S256' || ! preg_match('/^[A-Za-z0-9_\-]{43,128}$/', (string) ($q['code_challenge'] ?? ''))) {
            return [null, 'This connection request is incomplete (PKCE is required). Start again from Claude.'];
        }

        return [$client, null];
    }

    private function staffError(User $user): ?string
    {
        $client = $user->isClientRole() && \App\Support\ClientAccess::can($user, 'claude_api');
        if (! in_array($user->role, User::FIRM_STAFF_ROLES, true) && ! $client) {
            return 'Your portal access does not include connecting Claude. Ask your portal admin.';
        }
        if (! Keys::mcpEnabled()) {
            return 'The Claude connector is turned off. Ask your System Admin to turn it on in Integrations.';
        }

        return null;
    }

    /** The consent page (the user is already signed in; the auth middleware handles login). */
    public function consent(Request $request)
    {
        $q = $request->only(['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource']);
        [$client, $error] = $this->checkRequest($q);
        $error ??= $this->staffError($request->user());

        return Inertia::render('OAuthConsent', [
            'error' => $error,
            'client' => $client ? ['name' => $client->name, 'redirect_host' => parse_url($q['redirect_uri'], PHP_URL_HOST)] : null,
            'user' => ['name' => $request->user()->name, 'email' => $request->user()->email],
            'params' => $q,
        ]);
    }

    public function decide(Request $request)
    {
        $q = $request->only(['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource']);
        [$client, $error] = $this->checkRequest($q);
        $error ??= $this->staffError($request->user());
        if ($error) {
            return back()->withErrors(['oauth' => $error]);
        }
        $sep = str_contains($q['redirect_uri'], '?') ? '&' : '?';
        $state = isset($q['state']) && $q['state'] !== '' ? ['state' => (string) $q['state']] : [];

        if ($request->input('decision') !== 'approve') {
            return Inertia::location($q['redirect_uri'].$sep.http_build_query(['error' => 'access_denied'] + $state));
        }

        $code = Str::random(64);
        DB::table('oauth_auth_codes')->insert([
            'code_hash' => hash('sha256', $code), 'client_id' => $client->client_id, 'user_id' => $request->user()->id,
            'redirect_uri' => $q['redirect_uri'], 'code_challenge' => $q['code_challenge'],
            'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now(),
        ]);
        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => 'claude_connector_approved', 'subject_type' => 'OAuthClient',
            'metadata' => ['client' => $client->name, 'client_id' => $client->client_id],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return Inertia::location($q['redirect_uri'].$sep.http_build_query(['code' => $code] + $state));
    }

    public function token(Request $request): JsonResponse
    {
        return match ((string) $request->input('grant_type')) {
            'authorization_code' => $this->exchangeCode($request),
            'refresh_token' => $this->refresh($request),
            default => $this->oauthError('unsupported_grant_type', 'Use authorization_code or refresh_token.'),
        };
    }

    private function exchangeCode(Request $request): JsonResponse
    {
        $hash = hash('sha256', (string) $request->input('code'));
        $row = DB::table('oauth_auth_codes')->where('code_hash', $hash)->first();
        if (! $row || $row->used_at || now()->greaterThan($row->expires_at)
            || $row->client_id !== (string) $request->input('client_id') || $row->redirect_uri !== (string) $request->input('redirect_uri')) {
            return $this->oauthError('invalid_grant', 'The authorization code is invalid, expired or already used.');
        }
        $verifier = (string) $request->input('code_verifier');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (! preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier) || ! hash_equals($row->code_challenge, $challenge)) {
            return $this->oauthError('invalid_grant', 'PKCE verification failed.');
        }
        // Single use, even under a race.
        if (DB::table('oauth_auth_codes')->where('id', $row->id)->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]) !== 1) {
            return $this->oauthError('invalid_grant', 'The authorization code was already used.');
        }
        $user = User::find($row->user_id);
        if (! $user || ($user->status ?? 'Active') !== 'Active' || $this->staffError($user)) {
            return $this->oauthError('invalid_grant', 'This account may not connect Claude.');
        }

        return $this->issue($user, $row->client_id);
    }

    private function refresh(Request $request): JsonResponse
    {
        $row = DB::table('oauth_refresh_tokens')->where('token_hash', hash('sha256', (string) $request->input('refresh_token')))->first();
        if (! $row || $row->revoked_at || now()->greaterThan($row->expires_at)
            || ($request->filled('client_id') && $row->client_id !== (string) $request->input('client_id'))) {
            return $this->oauthError('invalid_grant', 'The refresh token is invalid or expired. Reconnect Claude.');
        }
        $user = User::find($row->user_id);
        if (! $user || ($user->status ?? 'Active') !== 'Active' || $this->staffError($user)) {
            return $this->oauthError('invalid_grant', 'This account may not connect Claude.');
        }
        if (DB::table('oauth_refresh_tokens')->where('id', $row->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]) !== 1) {
            return $this->oauthError('invalid_grant', 'The refresh token was already used.');
        }
        if ($row->access_token_id) {
            PersonalAccessToken::whereKey($row->access_token_id)->delete();
        }

        return $this->issue($user, $row->client_id);
    }

    private function issue(User $user, string $clientId): JsonResponse
    {
        $name = DB::table('oauth_clients')->where('client_id', $clientId)->value('name') ?: 'Claude';
        $access = $user->createToken('Claude connector: '.mb_substr($name, 0, 150), [McpController::ABILITY], now()->addSeconds(self::ACCESS_TTL));
        $refresh = Str::random(80);
        DB::table('oauth_refresh_tokens')->insert([
            'token_hash' => hash('sha256', $refresh), 'client_id' => $clientId, 'user_id' => $user->id,
            'access_token_id' => $access->accessToken->id, 'expires_at' => now()->addDays(self::REFRESH_DAYS),
            'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json([
            'access_token' => $access->plainTextToken, 'token_type' => 'Bearer', 'expires_in' => self::ACCESS_TTL,
            'refresh_token' => $refresh, 'scope' => McpController::ABILITY,
        ], 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    /** RFC 7009: revoke a refresh token (and its access token). Always 200. */
    public function revoke(Request $request): JsonResponse
    {
        $token = (string) $request->input('token');
        $row = DB::table('oauth_refresh_tokens')->where('token_hash', hash('sha256', $token))->first();
        if ($row) {
            DB::table('oauth_refresh_tokens')->where('id', $row->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
            if ($row->access_token_id) {
                PersonalAccessToken::whereKey($row->access_token_id)->delete();
            }
        } elseif (($pat = PersonalAccessToken::findToken($token)) && in_array(McpController::ABILITY, (array) $pat->abilities, true)) {
            $pat->delete();
        }

        return response()->json([]);
    }

    private function oauthError(string $error, string $description): JsonResponse
    {
        return response()->json(['error' => $error, 'error_description' => $description], 400, ['Cache-Control' => 'no-store']);
    }

    // ── Settings → Profile → Connected apps ────────────────────────────────

    public function connections(Request $request): JsonResponse
    {
        $rows = DB::table('oauth_refresh_tokens as r')->join('oauth_clients as c', 'c.client_id', '=', 'r.client_id')
            ->where('r.user_id', $request->user()->id)->whereNull('r.revoked_at')->where('r.expires_at', '>', now())
            ->groupBy('r.client_id', 'c.name')
            ->selectRaw('r.client_id, c.name, MIN(r.created_at) AS connected_at, MAX(r.last_used_at) AS last_used_at')
            ->orderByDesc('last_used_at')->get();

        return response()->json($rows->map(fn ($r) => ['client_id' => $r->client_id, 'name' => $r->name, 'connected_at' => $r->connected_at, 'last_used_at' => $r->last_used_at])->values());
    }

    public function disconnect(Request $request, string $clientId): JsonResponse
    {
        $user = $request->user();
        $rows = DB::table('oauth_refresh_tokens')->where('user_id', $user->id)->where('client_id', $clientId)->get(['id', 'access_token_id']);
        DB::table('oauth_refresh_tokens')->whereIn('id', $rows->pluck('id'))->update(['revoked_at' => now(), 'updated_at' => now()]);
        PersonalAccessToken::whereIn('id', $rows->pluck('access_token_id')->filter())->where('tokenable_id', $user->id)->delete();
        AuditLog::create([
            'user_id' => $user->id, 'action' => 'claude_connector_disconnected', 'subject_type' => 'OAuthClient',
            'metadata' => ['client_id' => $clientId], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }
}
