<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Central writer for in-app notifications (ip_notifications).
 *
 * Every call site used to hand-build the insert array, which drifted from the
 * real table schema (stray `message`/`is_read`/`action_url` columns from the
 * legacy `notifications` table) and silently threw. Route all writes through
 * here so the shape is correct in exactly one place, and a notification failure
 * can never break the business operation that triggered it.
 */
class Notifier
{
    /**
     * @param int|array<int>|\Illuminate\Support\Collection $userIds one or many recipient user IDs
     */
    public static function push(
        $userIds,
        string $type,
        string $title,
        string $description,
        ?string $actionUrl = null,
        array $meta = []
    ): void {
        $ids = collect(is_array($userIds) || $userIds instanceof \Illuminate\Support\Collection ? $userIds : [$userIds])
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $now  = now();
        $rows = $ids->map(fn ($uid) => [
            'user_id'     => (int) $uid,
            'type'        => $type,
            'title'       => $title,
            'description' => $description,
            'meta'        => json_encode($meta ?: (object) []),
            'action_url'  => $actionUrl,
            'read_at'     => null,
            'created_at'  => $now,
            'updated_at'  => $now,
        ])->all();

        try {
            DB::table('ip_notifications')->insert($rows);
        } catch (\Throwable $e) {
            // A failed notification must never bubble up and 500 the caller.
            report($e);
        }
    }
}
