<?php

namespace App\Http\Controllers;

use App\Services\TurnstileVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Enquiry form on the public marketing site (myipstrategy.com). Unauthenticated, so it is
 * guarded by validation, a honeypot, a minimum-fill-time check, Turnstile and rate limits.
 */
class PublicContactController extends Controller
{
    public function store(Request $request, TurnstileVerificationService $turnstile): JsonResponse
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'min:2', 'max:100'],
            'email'        => ['required', 'email:rfc', 'max:150'],
            'organization' => ['nullable', 'string', 'max:150'],
            'message'      => ['required', 'string', 'min:10', 'max:2000'],
            'website'      => ['nullable', 'string', 'max:255'],
            'elapsed_ms'   => ['nullable', 'integer', 'min:0'],
        ]);

        // Honeypot filled, or the form was submitted faster than a person could fill it:
        // pretend success so bots get no signal to adapt to.
        $tooFast = isset($data['elapsed_ms']) && (int) $data['elapsed_ms'] < 3000;
        if (! empty($data['website']) || $tooFast) {
            return response()->json(['message' => 'Thank you.']);
        }

        if (! $turnstile->verify($request)) {
            return response()->json(['message' => 'Verification failed. Please try again.'], 422);
        }

        $recipient = config('services.contact.recipient');
        if (! $recipient) {
            Log::error('Public contact form submitted but CONTACT_RECIPIENT is not configured.');
            return response()->json(['message' => 'Contact is temporarily unavailable.'], 503);
        }

        // Strip CR/LF so user input can never inject mail headers.
        $clean = fn (?string $v): string => trim(preg_replace('/[\r\n]+/', ' ', (string) $v));
        $name = $clean($data['name']);
        $org = $clean($data['organization'] ?? '');

        $body = "New website enquiry\n\n"
            ."Name: {$name}\n"
            ."Email: {$data['email']}\n"
            .'Organization: '.($org !== '' ? $org : '-')."\n"
            .'IP: '.$request->ip()."\n\n"
            .$data['message']."\n";

        try {
            Mail::raw($body, function ($m) use ($recipient, $data, $name) {
                $m->to($recipient)
                    ->replyTo($data['email'], $name)
                    ->subject('Website enquiry from '.$name);
            });
        } catch (\Throwable $e) {
            Log::error('Public contact mail failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'We could not send your message. Please try again shortly.'], 503);
        }

        return response()->json(['message' => 'Thank you. We will be in touch shortly.'], 201);
    }
}
