<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\PaginationHelper;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $query = DB::table('ip_notifications')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at');

        $perPage = (int) $request->query('per_page', 25);
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min($perPage, 500));

        $total = $query->count();
        $items = $query->forPage($page, $perPage)->get()
            ->map(fn($n) => [
                'id'          => $n->id,
                'type'        => $n->type,
                'title'       => $n->title,
                'description' => $n->description,
                'meta'        => json_decode($n->meta ?? '{}', true),
                'action_url'  => $n->action_url ?? null,
                'read'        => (bool) $n->read_at,
                'created_at'  => $n->created_at,
            ]);

        $hasMore = ($page * $perPage) < $total;
        return response()->json([
            'data' => $items,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int) ceil($total / $perPage),
            'has_more' => $hasMore,
        ]);
    }

    public function unreadCount(Request $request)
    {
        $count = DB::table('ip_notifications')
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['count' => $count]);
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
