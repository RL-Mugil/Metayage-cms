<?php

namespace App\Services;

/**
 * Entry point the rest of the app uses to report an operational problem. It no longer sends anything by itself:
 * problems are collected quietly in ops_incidents (see OpsIncidentService) and summarised in ONE email each morning
 * (OpsDailyReport). A recovery email is sent only when a problem that was already reported clears up.
 */
class OpsAlertService
{
    /** The only address operational mail is sent to. Directors and system admins are never emailed automatically. */
    public function emailRecipients(): array
    {
        $address = trim((string) config('services.ops_alerts.email'));

        return $address !== '' ? [$address] : [];
    }

    /** Report a caught or uncaught exception. */
    public function alertException(\Throwable $e, string $context = ''): void
    {
        app(OpsIncidentService::class)->recordException($e);
    }

    /**
     * Report a problem described in words. $dedupeKey identifies "the same problem" (repeats are counted, not repeated).
     * $dedupeMinutes is how long it must stay quiet before it counts as recovered.
     */
    public function alert(string $title, string $body, ?string $dedupeKey = null, int $dedupeMinutes = 360): void
    {
        try {
            app(OpsIncidentService::class)->record(
                'manual|'.($dedupeKey ?? md5($title)), mb_substr($title, 0, 250), 'The system reported a problem: '.mb_substr($body, 0, 400),
                'The part of the system involved may not have completed what it was doing.', 'If this appears again in tomorrow\'s report, send the technical line to the developer.',
                mb_substr($body, 0, 600), max($dedupeMinutes, 15), OpsPlainEnglish::currentCommand(), 'app',
            );
        } catch (\Throwable) {
            // reporting a problem must never cause another one
        }
    }
}
