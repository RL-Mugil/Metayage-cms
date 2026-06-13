<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\AIQueryService;
use Illuminate\Http\Request;

class AIController extends Controller
{
    public function query(Request $request)
    {
        $request->validate([
            'query' => 'required|string|max:1000',
        ]);

        $user = $request->user();

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
            ]);

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json(['message' => 'AI service error. Please try again.'], 500);
        }
    }
}
