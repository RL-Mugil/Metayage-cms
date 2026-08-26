<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ReminderProfile;
use App\Services\DocketWorklistService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReminderProfileController extends Controller
{
    public function show(Request $request)
    {
        abort_if($request->user()->isClientRole(), 403);
        $profile = ReminderProfile::firstOrCreate(
            ['user_id' => $request->user()->id, 'name' => 'My Daily Docket'],
            $this->defaults($request)
        );
        return response()->json($profile);
    }

    public function update(Request $request)
    {
        abort_if($request->user()->isClientRole(), 403);
        $validated = $request->validate([
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'timezone' => ['required', 'timezone'], 'send_time' => ['required', 'date_format:H:i'],
            'horizon_days' => ['required', 'integer', 'between:1,365'],
            'recipients' => ['required', 'array', 'min:1', 'max:20'], 'recipients.*' => ['email:rfc'],
            'filters' => ['nullable', 'array'], 'columns' => ['nullable', 'array'], 'columns.*' => ['string', 'max:64'],
            'color_bands' => ['required', 'array'], 'color_bands.red' => ['required', 'integer', 'between:1,30'],
            'color_bands.amber' => ['required', 'integer', 'between:2,90', 'gt:color_bands.red'],
            'send_empty' => ['required', 'boolean'], 'email_enabled' => ['required', 'boolean'],
            'in_app_enabled' => ['required', 'boolean'], 'critical_alerts_enabled' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
        ]);

        $profile = DB::transaction(function () use ($request, $validated): ReminderProfile {
            $profile = ReminderProfile::where('user_id', $request->user()->id)->where('name', 'My Daily Docket')->lockForUpdate()->first();
            if (! $profile) $profile = ReminderProfile::create(['user_id' => $request->user()->id, 'name' => 'My Daily Docket'] + $this->defaults($request));
            $profile->update($validated);
            AuditLog::create(['user_id' => $request->user()->id, 'action' => 'update_reminder_profile', 'subject_type' => 'ReminderProfile',
                'subject_id' => $profile->id, 'metadata' => ['frequency' => $profile->frequency, 'horizon_days' => $profile->horizon_days],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
            return $profile;
        });
        return response()->json($profile);
    }

    public function preview(Request $request, DocketWorklistService $service)
    {
        abort_if($request->user()->isClientRole(), 403);
        $profile = ReminderProfile::where('user_id', $request->user()->id)->where('name', 'My Daily Docket')->first();
        $filters = ($profile?->filters ?? []) + ['horizon_days' => $profile?->horizon_days ?? 60];
        $items = $service->query($request->user(), $filters)->limit(500)->get()->map(fn ($deadline) => $service->serialize($deadline));
        return response()->json(['generated_at' => now()->toIso8601String(), 'profile' => $profile, 'counts' => [
            'overdue' => $items->where('band', 'overdue')->count(), 'red' => $items->where('band', 'red')->count(),
            'amber' => $items->where('band', 'amber')->count(), 'green' => $items->where('band', 'green')->count(),
        ], 'data' => $items]);
    }

    private function defaults(Request $request): array
    {
        return ['frequency' => 'daily', 'timezone' => 'Asia/Kolkata', 'send_time' => '09:00', 'horizon_days' => 60,
            'recipients' => [$request->user()->email], 'filters' => [],
            'columns' => ['due_date', 'docket', 'client', 'event', 'responsible_user'],
            'color_bands' => ['red' => 7, 'amber' => 30], 'send_empty' => false,
            'email_enabled' => true, 'in_app_enabled' => true, 'critical_alerts_enabled' => true, 'active' => true];
    }
}
