<?php

namespace App\Http\Controllers;

use App\Ai\Assistant;
use App\Ai\Keys;
use App\Ai\MissingKeyException;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** AI Assistant page: read-only questions answered through the portal tools (App\Ai). */
class AIController extends Controller
{
    private function denied(Request $request): bool
    {
        return ! in_array($request->user()->role, User::FIRM_STAFF_ROLES, true);
    }

    /** Which providers are set up, and the Claude connector address. */
    public function providers(Request $request)
    {
        if ($this->denied($request)) {
            return response()->json(['message' => 'AI Assistant is not available for this account.'], 403);
        }

        return response()->json([
            'claude' => Keys::available('claude'),
            'groq' => Keys::available('groq'),
            'mcp_enabled' => Keys::mcpEnabled(),
            'mcp_url' => rtrim((string) config('app.url'), '/').'/mcp',
        ]);
    }

    public function query(Request $request, Assistant $assistant)
    {
        $user = $request->user();
        if ($this->denied($request)) {
            return response()->json(['message' => 'AI Assistant is not available for this account.'], 403);
        }

        $validated = $request->validate([
            'query' => 'required|string|max:2000',
            'provider' => 'nullable|in:claude,groq',
            'history' => 'nullable|array|max:20',
            'history.*.role' => 'required|in:user,assistant',
            'history.*.content' => 'required|string|max:8000',
        ]);
        $provider = $validated['provider'] ?? (Keys::available('claude') ? 'claude' : 'groq');

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'ai_query',
            'metadata' => ['query' => $validated['query'], 'provider' => $provider],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        try {
            return response()->json($assistant->ask($user, $provider, $validated['query'], $validated['history'] ?? []));
        } catch (MissingKeyException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('AI query failed', ['user_id' => $user->id, 'provider' => $provider, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'The AI service did not answer. Please try again in a moment.'], 502);
        }
    }
}
