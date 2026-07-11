<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleCalendarService
{
    private const AUTH_URI     = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URI    = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URI   = 'https://oauth2.googleapis.com/revoke';
    private const CALENDAR_API = 'https://www.googleapis.com/calendar/v3';
    private const SCOPES       = [
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/userinfo.email',
    ];

    public function buildAuthUrl(string $state): string
    {
        $params = http_build_query([
            'client_id'     => config('services.google.client_id'),
            'redirect_uri'  => config('services.google.redirect'),
            'response_type' => 'code',
            'scope'         => implode(' ', self::SCOPES),
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);
        return self::AUTH_URI . '?' . $params;
    }

    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post(self::TOKEN_URI, [
            'code'          => $code,
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri'  => config('services.google.redirect'),
            'grant_type'    => 'authorization_code',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Google token exchange failed: ' . $response->body());
        }

        return $response->json();
    }

    public function refreshToken(User $user): ?string
    {
        $stored = json_decode($user->google_calendar_token ?? '{}', true);
        if (empty($stored['refresh_token'])) {
            return null;
        }

        // If access token still valid (with 60s buffer), return it
        if (! empty($stored['access_token']) && ($stored['expires_at'] ?? 0) > (time() + 60)) {
            return $stored['access_token'];
        }

        $response = Http::asForm()->post(self::TOKEN_URI, [
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $stored['refresh_token'],
            'grant_type'    => 'refresh_token',
        ]);

        if (! $response->successful()) {
            Log::warning('Google token refresh failed for user ' . $user->id, ['body' => $response->body()]);
            return null;
        }

        $tokens = $response->json();
        $stored['access_token'] = $tokens['access_token'];
        $stored['expires_at']   = time() + ($tokens['expires_in'] ?? 3600);

        $user->google_calendar_token = json_encode($stored);
        $user->save();

        return $tokens['access_token'];
    }

    public function getUserEmail(string $accessToken): ?string
    {
        $res = Http::withToken($accessToken)->get('https://www.googleapis.com/oauth2/v2/userinfo');
        return $res->successful() ? ($res->json('email') ?? null) : null;
    }

    /**
     * Push a deadline as a Google Calendar event for the user.
     * Returns the event ID on success, null on failure.
     */
    public function upsertEvent(User $user, array $event): ?string
    {
        $token = $this->refreshToken($user);
        if (! $token) return null;

        $body = [
            'summary'     => $event['title'],
            'description' => $event['description'] ?? '',
            'start'       => ['date' => $event['date']],
            'end'         => ['date' => $event['date']],
            'colorId'     => '11',  // Tomato — deadline colour
            'source'      => [
                'title' => 'IPFlow',
                'url'   => config('app.url') . ($event['url'] ?? '/projects'),
            ],
        ];

        $eventId = $event['google_event_id'] ?? null;

        if ($eventId) {
            $res = Http::withToken($token)
                ->put(self::CALENDAR_API . "/calendars/primary/events/{$eventId}", $body);
        } else {
            $res = Http::withToken($token)
                ->post(self::CALENDAR_API . '/calendars/primary/events', $body);
        }

        if ($res->successful()) {
            return $res->json('id');
        }

        Log::warning('Google Calendar event upsert failed', [
            'user'   => $user->id,
            'status' => $res->status(),
            'body'   => $res->body(),
        ]);
        return null;
    }

    public function deleteEvent(User $user, string $eventId): void
    {
        $token = $this->refreshToken($user);
        if (! $token) return;

        Http::withToken($token)
            ->delete(self::CALENDAR_API . "/calendars/primary/events/{$eventId}");
    }

    public function disconnect(User $user): void
    {
        $stored = json_decode($user->google_calendar_token ?? '{}', true);
        if (! empty($stored['access_token'])) {
            Http::asForm()->post(self::REVOKE_URI, ['token' => $stored['access_token']]);
        }
        $user->google_calendar_token = null;
        $user->google_calendar_email = null;
        $user->save();
    }
}
