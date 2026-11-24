<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\NotificationChannels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Each person's delivery choice per kind of notification (in-app, email, both, off). */
class NotificationChannelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'categories' => NotificationChannels::CATEGORIES,
            'channels' => collect(NotificationChannels::CATEGORIES)->keys()->mapWithKeys(fn ($c) => [$c => NotificationChannels::channelFor($user, $c)]),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $rules = collect(NotificationChannels::CATEGORIES)->keys()
            ->mapWithKeys(fn ($c) => ["channels.{$c}" => 'sometimes|in:'.implode(',', NotificationChannels::CHANNELS)])->all();
        $data = $request->validate(['channels' => 'required|array'] + $rules);
        $user = $request->user();
        DB::transaction(function () use ($user, $data, $request) {
            $prefs = $user->notification_prefs ?? [];
            $prefs['channels'] = array_merge($prefs['channels'] ?? [], array_intersect_key($data['channels'], NotificationChannels::CATEGORIES));
            $user->notification_prefs = $prefs;
            $user->save();
            AuditLog::create(['user_id' => $user->id, 'action' => 'update', 'subject_type' => 'NotificationChannels', 'subject_id' => $user->id,
                'metadata' => $prefs['channels'], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
        });

        return $this->index($request);
    }
}
