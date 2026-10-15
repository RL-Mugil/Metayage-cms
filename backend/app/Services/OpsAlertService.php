<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Operational alerts for the people who run the system: system admins (super_admin) and directors (partner).
 * Each alert is an in-app notification plus an email. Alerts with the same dedupe key are sent at most once per
 * window so an error storm cannot flood inboxes. Never throws: alerting must not break the request or job that
 * triggered it.
 */
class OpsAlertService
{
    public const ROLES = ['super_admin', 'partner'];

    public function alert(string $title, string $body, ?string $dedupeKey = null, int $dedupeMinutes = 15): void
    {
        try {
            if ($dedupeKey !== null && ! Cache::add('ops-alert:'.md5($dedupeKey), 1, now()->addMinutes($dedupeMinutes))) {
                return;
            }

            $recipients = $this->recipients();
            if ($recipients->isEmpty()) {
                return;
            }

            $now = Carbon::now();
            DB::table('ip_notifications')->insert($recipients->map(fn (User $u) => [
                'user_id'     => $u->id,
                'type'        => 'system_alert',
                'title'       => mb_substr($title, 0, 250),
                'description' => mb_substr($body, 0, 2000),
                'meta'        => json_encode(['source' => 'ops-alert']),
                'created_at'  => $now,
                'updated_at'  => $now,
            ])->all());

            $emails = $recipients->pluck('email')->filter()->unique()->values()->all();
            $send = function () use ($emails, $title, $body): void {
                foreach ($emails as $email) {
                    try {
                        Mail::raw($body, fn ($m) => $m->to($email)->subject('[IPFlow alert] '.$title));
                    } catch (\Throwable $e) {
                        Log::warning('Ops alert email failed: '.$e->getMessage());
                    }
                }
            };
            // Send after the response so a slow mail relay never delays the failing request.
            app()->runningInConsole() ? $send() : app()->terminating($send);
        } catch (\Throwable $e) {
            Log::warning('Ops alert failed: '.$e->getMessage());
        }
    }

    /** Active system admins and directors. */
    public function recipients()
    {
        return User::query()->whereIn('role', self::ROLES)->where('status', 'Active')
            ->where('email', 'not like', '%@example.invalid') // reserved test domain: never mail it
            ->get(['id', 'email']);
    }
}
