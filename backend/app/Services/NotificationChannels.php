<?php

namespace App\Services;

use App\Mail\NotificationCopyMail;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Per-person delivery choice for each kind of in-app notification: in_app (default),
 * email, both, or off. Applied when a notification is created: "email" and "off" file it
 * as already read (it stays in the history), "email" and "both" also send an email copy
 * after the surrounding transaction commits.
 */
class NotificationChannels
{
    public const CATEGORIES = [
        'deadlines' => 'Deadlines and renewals',
        'payments' => 'Invoices and payments',
        'approvals' => 'Approvals and decisions',
        'tasks' => 'Tasks and assignments',
        'messages' => 'Discussions and questions',
        'other' => 'Everything else',
    ];

    public const CHANNELS = ['in_app', 'email', 'both', 'off'];

    /** Which category a notification belongs to, from its title, message and link. */
    public static function categorize(Notification $n): string
    {
        $text = strtolower($n->title.' '.$n->message.' '.$n->action_url);

        return match (true) {
            str_contains($text, 'deadline') || str_contains($text, 'renewal') || str_contains($text, 'annuity') || str_contains($text, 'tab=deadlines') => 'deadlines',
            str_contains($text, 'invoice') || str_contains($text, 'payment') || str_contains($text, 'quote') || str_contains($text, 'pending-payments') => 'payments',
            str_contains($text, 'approv') || str_contains($text, 'decision') => 'approvals',
            str_contains($text, 'task') || str_contains($text, 'assign') => 'tasks',
            str_contains($text, 'discussion') || str_contains($text, 'question') || str_contains($text, 'message') || str_contains($text, 'mention') => 'messages',
            default => 'other',
        };
    }

    public static function channelFor(User $user, string $category): string
    {
        $choice = $user->notification_prefs['channels'][$category] ?? 'in_app';

        return in_array($choice, self::CHANNELS, true) ? $choice : 'in_app';
    }

    /** Called from Notification::creating / created. */
    public static function apply(Notification $n, bool $afterCreate): void
    {
        $user = $n->user_id ? User::find($n->user_id, ['id', 'name', 'email', 'notification_prefs', 'status']) : null;
        if (! $user) {
            return;
        }
        $channel = self::channelFor($user, self::categorize($n));
        if (! $afterCreate) {
            if (in_array($channel, ['email', 'off'], true)) {
                $n->is_read = true;   // filed quietly; still listed in the notification history
            }

            return;
        }
        if (in_array($channel, ['email', 'both'], true) && $user->email && $user->status === 'Active') {
            DB::afterCommit(function () use ($user, $n) {
                try {
                    Mail::to($user->email)->send(new NotificationCopyMail($n->title, (string) $n->message,
                        $n->action_url ? rtrim((string) config('app.url'), '/').$n->action_url : null));
                } catch (\Throwable $e) {
                    Log::warning('Notification email failed', ['notification_id' => $n->id, 'error' => $e->getMessage()]);
                }
            });
        }
    }
}
