<?php

namespace App\Http\Controllers;

use App\Models\MobileDeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileDeviceController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'push_token' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'in:android,ios'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ]);

        MobileDeviceToken::query()->updateOrCreate(
            ['push_token' => $validated['push_token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'],
                'device_name' => $validated['device_name'] ?? 'Unknown device',
                'app_version' => $validated['app_version'] ?? null,
                'last_seen_at' => now(),
            ],
        );

        return response()->json(['ok' => true]);
    }

    public function unregister(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'push_token' => ['required', 'string', 'max:255'],
        ]);

        MobileDeviceToken::query()
            ->where('user_id', $request->user()->id)
            ->where('push_token', $validated['push_token'])
            ->delete();

        return response()->json(['ok' => true]);
    }
}
