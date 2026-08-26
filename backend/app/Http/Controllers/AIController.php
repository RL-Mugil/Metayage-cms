<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\AIQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AIController extends Controller
{
    public function query(Request $request)
    {
        $user = $request->user();
        if ($user->isClientRole()) {
            return response()->json(['message' => 'AI Assistant is not available for portal accounts.'], 403);
        }

        $request->validate([
            'query' => 'required|string|max:1000',
        ]);

        AuditLog::create([
            'user_id'    => $user->id,
            'action'     => 'ai_query',
            'metadata'   => ['query' => $request->input('query')],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        try {
            $service = new AIQueryService();
            $result  = $service->ask($request->input('query'), [
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
                'firm_id' => $request->attributes->get('firm_id'),
            ]);

            return response()->json($result);

        } catch (\Throwable $e) {
            Log::error('AI query failed', [
                'user_id' => $user->id,
                'query' => $request->input('query'),
                'error' => $e->getMessage(),
            ]);

            // Return a generic message — never leak upstream/internal error
            // detail (Groq errors, SQL, stack) to the client. Detail is logged above.
            return response()->json([
                'message' => 'AI service error. Please try again.',
            ], 500);
        }
    }
}
