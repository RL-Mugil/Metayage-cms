<?php

namespace App\Http\Controllers;

use App\Models\SiteEnquiry;
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
            'name'         => ['required_unless:source,canpatentit-ask', 'nullable', 'string', 'min:2', 'max:100'],
            'email'        => ['required', 'email:rfc', 'max:150'],
            'organization' => ['nullable', 'string', 'max:150'],
            'message'      => ['required', 'string', 'min:10', 'max:2000'],
            'stage'        => ['nullable', 'string', 'max:60'],
            'domain'       => ['nullable', 'string', 'max:80'],
            'source'       => ['nullable', 'string', 'in:myipstrategy,canpatentit,canpatentit-quiz,canpatentit-ask'],
            'answers'      => ['nullable', 'array', 'max:12'],
            'answers.*'    => ['nullable', 'string', 'max:60'],
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

        // Strip CR/LF so user input can never inject mail headers.
        $clean = fn (?string $v): string => trim(preg_replace('/[\r\n]+/', ' ', (string) $v));
        // The one-question helper only asks for an email so a person can reply; the name is optional there.
        $name = $clean($data['name'] ?? '') ?: 'Website visitor';
        $org = $clean($data['organization'] ?? '');

        $stage = $clean($data['stage'] ?? '');
        $domain = $clean($data['domain'] ?? '');
        $isQuiz = ($data['source'] ?? '') === 'canpatentit-quiz';
        $isAsk = ($data['source'] ?? '') === 'canpatentit-ask';
        $isCpi = $isQuiz || $isAsk || ($data['source'] ?? '') === 'canpatentit';

        $body = ($isQuiz ? 'New CanPatentIt self-check result' : ($isAsk ? 'New CanPatentIt question' : ($isCpi ? 'New CanPatentIt tech assessment' : 'New website enquiry')))."\n\n"
            ."Name: {$name}\n"
            ."Email: {$data['email']}\n"
            .'Organization: '.($org !== '' ? $org : '-')."\n"
            .($stage !== '' ? "Company stage: {$stage}\n" : '')
            .($domain !== '' ? "Technology area: {$domain}\n" : '')
            .'IP: '.$request->ip()."\n\n"
            .$data['message']."\n";

        // Keep every enquiry in the dashboard even if email delivery is down or unconfigured.
        $enquiry = SiteEnquiry::create([
            'source' => $isCpi ? 'canpatentit' : 'myipstrategy',
            'type' => $isQuiz ? 'quiz' : 'enquiry',
            'payload' => $isQuiz ? ($data['answers'] ?? null) : null,
            'name' => $name,
            'email' => $data['email'],
            'organization' => $org !== '' ? $org : null,
            'stage' => $stage !== '' ? $stage : null,
            'domain' => $domain !== '' ? $domain : null,
            'message' => $data['message'],
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        $recipient = config('services.contact.recipient');
        try {
            if (! $recipient) {
                throw new \RuntimeException('CONTACT_RECIPIENT is not configured.');
            }
            $subject = $isQuiz
                ? 'CanPatentIt self-check — '.$name
                : ($isAsk
                    ? 'CanPatentIt question — '.$name
                    : ($isCpi
                    ? 'CanPatentIt tech assessment — '.($org !== '' ? $org : $name)
                    : 'Website enquiry from '.$name));
            Mail::raw($body, function ($m) use ($recipient, $data, $name, $subject) {
                $m->to($recipient)
                    ->replyTo($data['email'], $name)
                    ->subject($subject);
            });
            $enquiry->update(['mail_status' => 'sent']);
        } catch (\Throwable $e) {
            Log::error('Public contact mail failed', ['error' => $e->getMessage()]);
            $enquiry->update(['mail_status' => 'failed', 'mail_error' => mb_substr($e->getMessage(), 0, 500)]);
        }

        return response()->json(['message' => 'Thank you. We will be in touch shortly.'], 201);
    }
}
