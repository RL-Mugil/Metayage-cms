<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AIController extends Controller
{
    public function query(Request $request)
    {
        $request->validate([
            'query' => 'required|string',
        ]);

        $user = $request->user();
        // config() instead of env() — env() returns null once config is cached.
        $sidecarUrl = config('services.ai_sidecar.url');

        // Log audit event before making AI query
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'ai_query',
            'metadata' => ['query' => $request->input('query')],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        try {
            // Forward request to FastAPI sidecar with user security scope
            $response = Http::withHeaders([
                'X-User-Id' => $user->id,
                'X-User-Role' => $user->role,
            ])->post("{$sidecarUrl}/api/query", [
                'query' => $request->input('query'),
                'context' => [
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                    'user_role' => $user->role,
                ]
            ]);

            if ($response->failed()) {
                return response()->json([
                    'message' => 'AI sidecar service returned an error.',
                    'details' => $response->json()
                ], $response->status());
            }

            return response()->json($response->json());

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Could not connect to AI sidecar service.',
                'error' => $e->getMessage()
            ], 502);
        }
    }
}
