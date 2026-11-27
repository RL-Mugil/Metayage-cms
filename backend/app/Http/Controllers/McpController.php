<?php

namespace App\Http\Controllers;

use App\Ai\Keys;
use App\Ai\Tool;
use App\Ai\ToolRegistry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * MCP server (Streamable HTTP, stateless, JSON responses) for the Claude connector.
 * Read-only: tools/list and tools/call expose only App\Ai tools, run as the token's user
 * with that user's access. Tokens come from the OAuth flow (OAuthController), ability mcp:read.
 */
class McpController extends Controller
{
    public const ABILITY = 'mcp:read';

    private const VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(private ToolRegistry $registry) {}

    public function get(): JsonResponse
    {
        return response()->json(['message' => 'Use POST for MCP requests.'], 405, ['Allow' => 'POST']);
    }

    public function handle(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $user = $this->authenticate($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Same firm scoping as the portal pages (ResolveFirmContext reads the request user).
        $request->setUserResolver(fn () => $user);

        return app(\App\Http\Middleware\ResolveFirmContext::class)->handle($request, fn (Request $r) => $this->dispatch($r, $user));
    }

    private function dispatch(Request $request, User $user): JsonResponse|\Illuminate\Http\Response
    {
        $msg = $request->json()->all();
        if (! is_array($msg) || array_is_list($msg) || ($msg['jsonrpc'] ?? null) !== '2.0' || ! isset($msg['method'])) {
            return $this->error($msg['id'] ?? null, -32600, 'Invalid Request');
        }
        $id = $msg['id'] ?? null;
        $params = (array) ($msg['params'] ?? []);

        // Notifications (no id) get no JSON-RPC response.
        if (! array_key_exists('id', $msg)) {
            return response('', 202);
        }

        return match ($msg['method']) {
            'initialize' => $this->result($id, [
                'protocolVersion' => in_array($params['protocolVersion'] ?? '', self::VERSIONS, true) ? $params['protocolVersion'] : self::VERSIONS[0],
                'capabilities' => ['tools' => ['listChanged' => false], 'prompts' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'myipstrategy-portal', 'title' => 'MyIPStrategy portal', 'version' => '1.0.0'],
                'instructions' => 'Access to the MyIPStrategy IP practice portal: clients, cases, deadlines, compliance, tasks, workload, documents, tracker, '
                    .'finance and pending payments (where allowed), and the Reports module. '
                    ."Data is limited to what {$user->name} may see. Portal records cannot be created, edited or deleted. "
                    .'generate_report saves a report as an unsigned draft and returns a download link valid for 30 minutes: always give the user the link. '
                    .'To sign a report, ask the user for the signatory PIN and call sign_report; never repeat or store the PIN. Amounts are INR.',
            ]),
            'ping' => $this->result($id, (object) []),
            'tools/list' => $this->result($id, ['tools' => array_values(array_map(fn (Tool $t) => [
                'name' => $t->name(), 'title' => $t->title(), 'description' => $t->description(),
                'inputSchema' => $t->inputSchema() + ['type' => 'object'],
                'annotations' => ['title' => $t->title(), 'readOnlyHint' => $t->readOnly(), 'destructiveHint' => false, 'idempotentHint' => $t->readOnly(), 'openWorldHint' => false],
            ], $this->registry->for($user)))]),
            'tools/call' => $this->call($user, $id, $params),
            'prompts/list' => $this->result($id, ['prompts' => app(\App\Ai\Prompts::class)->list($user)]),
            'prompts/get' => ($prompt = app(\App\Ai\Prompts::class)->get($user, (string) ($params['name'] ?? ''), (array) ($params['arguments'] ?? [])))
                ? $this->result($id, $prompt) : $this->error($id, -32602, 'Unknown prompt'),
            default => $this->error($id, -32601, 'Method not found'),
        };
    }

    private function call(User $user, $id, array $params): JsonResponse
    {
        $name = (string) ($params['name'] ?? '');
        if (! isset($this->registry->for($user)[$name])) {
            return $this->error($id, -32602, "Unknown tool: {$name}");
        }
        try {
            $data = $this->registry->call($user, $name, (array) ($params['arguments'] ?? []), 'mcp');
        } catch (\Throwable $e) {
            Log::error('MCP tool failed', ['user_id' => $user->id, 'tool' => $name, 'error' => $e->getMessage()]);
            $data = ['error' => 'The portal could not answer that just now.'];
        }
        $isError = isset($data['error']) && count($data) === 1;
        $content = [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)]];
        // Files the tool produced (30-minute download links) also go out as resource links.
        foreach ((array) ($data['files'] ?? []) as $file) {
            if (is_array($file) && ! empty($file['url'])) {
                $content[] = ['type' => 'resource_link', 'uri' => $file['url'], 'name' => (string) ($file['name'] ?? 'file'),
                    'mimeType' => (string) ($file['mime'] ?? 'application/octet-stream'), 'description' => 'Download link, valid until '.($file['expires_at'] ?? '30 minutes')];
            }
        }

        return $this->result($id, [
            'content' => $content,
            'structuredContent' => $data,
            'isError' => $isError,
        ]);
    }

    /** The token's user, or the HTTP error to return. */
    private function authenticate(Request $request): User|JsonResponse
    {
        $metadata = rtrim((string) config('app.url'), '/').'/.well-known/oauth-protected-resource';
        $unauthorized = fn (string $why) => response()->json(['error' => 'invalid_token', 'error_description' => $why], 401, [
            'WWW-Authenticate' => 'Bearer realm="mcp", resource_metadata="'.$metadata.'"',
        ]);

        $user = Auth::guard('sanctum')->user();
        $token = $user?->currentAccessToken();
        if (! $user instanceof User || ! $token instanceof PersonalAccessToken || ! in_array(self::ABILITY, (array) $token->abilities, true)) {
            return $unauthorized('Sign in to the portal from Claude to connect.');
        }
        $allowed = in_array($user->role, User::FIRM_STAFF_ROLES, true)
            // A client's login with "Claude, API & integrations" (their own data only: App\Ai\ClientTools).
            || ($user->isClientRole() && \App\Support\ClientAccess::can($user, 'claude_api') && \App\Models\Client::forUser($user)?->portal_enabled);
        if (! $allowed || ($user->status ?? 'Active') !== 'Active') {
            return response()->json(['error' => 'forbidden', 'error_description' => 'This login may not connect Claude.'], 403);
        }
        if (! Keys::mcpEnabled()) {
            return response()->json(['error' => 'unavailable', 'error_description' => 'The Claude connector is turned off on the Integrations page.'], 503);
        }

        return $user;
    }

    private function result($id, $result): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function error($id, int $code, string $message): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }
}
