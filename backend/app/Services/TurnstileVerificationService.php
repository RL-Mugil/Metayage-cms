<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifies a Cloudflare Turnstile token (the `cf-turnstile-response` field submitted by the
 * widget) against Cloudflare's siteverify endpoint. Shared by every public-facing auth form —
 * AuthController::login() and PasswordResetController's sendLink()/reset() — so the bot check
 * covers not just sign-in but the "email me a reset link" and "set new password" endpoints too.
 */
class TurnstileVerificationService
{
    /**
     * Silently passes (returns true) when TURNSTILE_SECRET_KEY isn't configured — same
     * graceful-degrade convention as the other optional third-party integrations in
     * config/services.php (e.g. Groq) — so dev/local environments without a Cloudflare account
     * still work, and these forms never hard-lock on a missing key.
     */
    public function verify(Request $request): bool
    {
        $secret = config('services.turnstile.secret_key');
        if (! $secret) {
            return true;
        }

        $token = $request->input('cf-turnstile-response');
        if (! $token) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(
                'https://challenges.cloudflare.com/turnstile/v0/siteverify',
                [
                    'secret'   => $secret,
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ]
            );

            return (bool) $response->json('success');
        } catch (\Throwable $e) {
            // Cloudflare unreachable/timed out — fail closed rather than silently disabling the
            // bot check, but log it since this would otherwise look like a mass validation failure.
            Log::warning('Turnstile verification request failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
