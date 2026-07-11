<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    /** Receive an inbound webhook from an external integration. Public endpoint (no auth). */
    public function receive(Request $request, string $slug)
    {
        $exists = Integration::where('slug', $slug)->where('connected', true)->exists();
        if (!$exists) {
            return response()->json(['ok' => false, 'message' => 'Integration not found or not connected.'], 404);
        }

        DB::table('integration_logs')->insert([
            'slug'       => $slug,
            'event_type' => 'webhook',
            'status'     => 'ok',
            'summary'    => 'Inbound webhook received',
            'payload'    => json_encode($request->all()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /** List recent logs for a given integration (admin only). */
    public function logs(Request $request, string $slug)
    {
        if (!in_array($request->user()->role, ['super_admin', 'partner', 'manager'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $logs = DB::table('integration_logs')
            ->where('slug', $slug)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'id'         => $r->id,
                'event_type' => $r->event_type,
                'status'     => $r->status,
                'summary'    => $r->summary,
                'created_at' => $r->created_at,
            ]);

        return response()->json($logs);
    }
}
