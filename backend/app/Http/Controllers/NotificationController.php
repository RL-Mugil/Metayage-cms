<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $items = DB::table('ip_notifications')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(60)
            ->get()
            ->map(fn($n) => [
                'id'          => $n->id,
                'type'        => $n->type,
                'title'       => $n->title,
                'description' => $n->description,
                'meta'        => json_decode($n->meta ?? '{}', true),
                'read'        => (bool) $n->read_at,
                'created_at'  => $n->created_at,
            ]);

        return response()->json($items);
    }

    public function markAllRead(Request $request)
    {
        DB::table('ip_notifications')
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function markRead(Request $request, $id)
    {
        DB::table('ip_notifications')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function dismiss(Request $request, $id)
    {
        DB::table('ip_notifications')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['ok' => true]);
    }
}
