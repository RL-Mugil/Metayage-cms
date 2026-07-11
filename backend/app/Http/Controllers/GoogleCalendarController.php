<?php

namespace App\Http\Controllers;

use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GoogleCalendarController extends Controller
{
    public function __construct(private GoogleCalendarService $gcal) {}

    /** Redirect the user to Google's OAuth consent screen. */
    public function connect(Request $request)
    {
        $state = Str::random(32);
        $request->session()->put('gcal_oauth_state', $state);

        return redirect($this->gcal->buildAuthUrl($state));
    }

    /** Google sends the user back here with ?code=... */
    public function callback(Request $request)
    {
        $error = $request->query('error');
        if ($error) {
            return redirect('/integrations?gcal=error&reason=' . urlencode($error));
        }

        $state = $request->query('state');
        if ($state !== $request->session()->pull('gcal_oauth_state')) {
            return redirect('/integrations?gcal=error&reason=state_mismatch');
        }

        $code = $request->query('code');
        if (! $code) {
            return redirect('/integrations?gcal=error&reason=no_code');
        }

        try {
            $tokens      = $this->gcal->exchangeCode($code);
            $accessToken = $tokens['access_token'];
            $email       = $this->gcal->getUserEmail($accessToken);

            $stored = [
                'access_token'  => $accessToken,
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'expires_at'    => time() + ($tokens['expires_in'] ?? 3600),
            ];

            $user = $request->user();
            $user->google_calendar_token = json_encode($stored);
            $user->google_calendar_email = $email;
            $user->save();

            return redirect('/integrations?gcal=connected');
        } catch (\Throwable $e) {
            \Log::error('Google Calendar callback error', ['msg' => $e->getMessage()]);
            return redirect('/integrations?gcal=error&reason=exchange_failed');
        }
    }

    /** Disconnect — revoke tokens and clear from DB. */
    public function disconnect(Request $request)
    {
        $this->gcal->disconnect($request->user());
        return response()->json(['ok' => true]);
    }

    /** Current connection status for the logged-in user. */
    public function status(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'connected' => ! empty($user->google_calendar_token),
            'email'     => $user->google_calendar_email,
        ]);
    }
}
