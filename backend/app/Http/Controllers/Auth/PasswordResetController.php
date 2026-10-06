<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\TurnstileVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PasswordResetController extends Controller
{
    public function showLinkRequest()
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function sendLink(Request $request, TurnstileVerificationService $turnstile)
    {
        $request->validate(['email' => 'required|email']);

        if (! $turnstile->verify($request)) {
            throw ValidationException::withMessages([
                'email' => ['We could not verify you\'re not a robot. Please try again.'],
            ]);
        }

        $status = Password::sendResetLink($request->only('email'));

        // Always report success to avoid leaking which emails exist.
        if ($status === Password::RESET_LINK_SENT) {
            AuditLog::create([
                'user_id' => null, 'action' => 'password_reset_requested',
                'metadata' => ['email' => $request->email],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
        }

        return back()->with('success', 'If that email is registered, a reset link has been sent.');
    }

    public function showReset(Request $request, string $token)
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function reset(Request $request, TurnstileVerificationService $turnstile)
    {
        $request->validate([
            'token'    => 'required',
            'email'    => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->symbols()],
        ]);

        if (! $turnstile->verify($request)) {
            throw ValidationException::withMessages([
                'email' => ['We could not verify you\'re not a robot. Please try again.'],
            ]);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        AuditLog::create([
            'user_id' => null, 'action' => 'password_reset_completed',
            'metadata' => ['email' => $request->email],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return redirect('/login')->with('success', 'Password reset. You can now sign in.');
    }
}
