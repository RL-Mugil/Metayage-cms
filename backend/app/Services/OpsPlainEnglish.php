<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;

/**
 * Turns a raw exception into something a non-technical reader can act on: what happened, what it affects, what to do.
 * Also decides what counts as "the same problem" (the fingerprint) so a storm of repeats becomes one line in the report.
 */
class OpsPlainEnglish
{
    /** Friendly names and consequences for the scheduled tasks. */
    private const TASKS = [
        'zoho:poll' => ['Zoho Books update check (every 5 minutes)', 'Changes made in Zoho Books (new or edited invoices and quotes) may reach the portal a few minutes late.'],
        'zoho:sync' => ['Nightly Zoho Books sync', 'Invoice and quote figures in the portal may not match Zoho Books until the next successful sync.'],
        'zoho:reconcile-deleted' => ['Hourly check for invoices deleted in Zoho Books', 'An invoice or quote deleted in Zoho Books could stay in the portal totals a little longer.'],
        'reminders:send-deadlines' => ['Daily deadline reminders', 'Some staff may not receive their daily deadline and document reminders until this is fixed.'],
        'reminders:send-docket-digests' => ['Docket digest emails', 'Scheduled docket digest emails may not be sent.'],
        'docket:notify-deadlines' => ['Daily docket deadline alerts', 'Some statutory deadline alerts may not be sent. Check deadlines manually until it is fixed.'],
        'compliance:sync' => ['Hourly compliance update', 'The compliance page may show slightly out-of-date information.'],
        'google:sync-completions' => ['Google Tasks sync', 'Tasks completed in Google Tasks may not show as completed in the portal yet.'],
        'ops:prune-operational' => ['Nightly clean-up of old logs', 'Old log entries are not being removed. This is harmless for now but the database will slowly grow.'],
        'ops:daily-report' => ['Daily health report', 'The morning health report may not be sent.'],
    ];

    /**
     * @return array{fingerprint: string, title: string, meaning: string, impact: string, action: string, technical: string, quiet: int, scope: ?string, wrapper: bool}
     */
    public static function describe(\Throwable $e): array
    {
        $message = $e->getMessage();
        $technical = mb_substr(class_basename($e).': '.preg_replace('/\s+/', ' ', $message), 0, 400).' ('.basename($e->getFile()).':'.$e->getLine().')';
        $scope = self::currentCommand();
        $follow = 'If this appears again in tomorrow\'s report, send the technical line below to the developer.';

        // Laravel's own "Scheduled command [...] failed with exit code" message: a repeat of an error reported separately.
        if (preg_match("~^Scheduled command \\[.*?'artisan' ([^\\s'\\]]+)~", $message, $m)) {
            [$name, $impact] = self::TASKS[$m[1]] ?? ["Scheduled task {$m[1]}", 'Whatever this task does automatically did not run to completion.'];

            return [
                'fingerprint' => 'schedule|'.$m[1], 'title' => "{$name} failed", 'scope' => $m[1], 'wrapper' => true, 'quiet' => 1500,
                'meaning' => 'A task the system runs automatically on a schedule stopped with an error.',
                'impact' => $impact, 'action' => 'Nothing to do yet: it usually fixes itself on the next run, and you get an immediate email if it recovers. '.$follow,
                'technical' => $technical,
            ];
        }

        if ($e instanceof ConnectionException) {
            $host = preg_match('~https?://([^/\s)?]+)~', $message, $m) ? $m[1] : 'an outside service';
            $zoho = str_contains($host, 'zohoapis') || str_contains($host, 'zoho');
            $timedOut = stripos($message, 'timed out') !== false;

            return [
                'fingerprint' => 'transient|'.$host, 'scope' => $scope, 'wrapper' => false, 'quiet' => 45,
                'title' => $zoho ? 'Zoho Books was slow or unreachable' : "Could not reach {$host}",
                'meaning' => $zoho
                    ? ($timedOut ? 'Zoho Books took too long to answer, so the portal gave up waiting and will try again.' : 'The portal could not connect to Zoho Books for a moment.')
                    : "The system could not get a reply from {$host}".($timedOut ? ' in time.' : '.'),
                'impact' => $zoho ? 'Invoice and quote changes from Zoho may reach the portal a few minutes late. Nothing is lost; the system retries every 5 minutes.' : 'Whatever depends on that service may have been delayed. It retries automatically.',
                'action' => 'Nothing, unless it keeps appearing for several days: then the connection to that service needs a look.',
                'technical' => $technical,
            ];
        }

        if ($e instanceof QueryException) {
            $state = (string) ($e->errorInfo[0] ?? '');
            [$what, $impact] = match (true) {
                $state === '22001' => ['The system tried to save a piece of text that was longer than the database allows.', 'That one record or log entry was not saved.'],
                $state === '23502' => ['The system tried to save a record with a required field left empty.', 'That one record was not saved, and a task that depends on it may have stopped part-way.'],
                $state === '23505' => ['The system tried to save something that already exists.', 'A duplicate was prevented; nothing is lost.'],
                in_array($state, ['40001', '40P01'], true) => ['Two actions touched the same data at the same moment and the database stopped one of them.', 'That action may have to be repeated; it usually succeeds on retry.'],
                str_starts_with($state, '08') => ['The application lost its connection to the database for a moment.', 'Pages or tasks running at that moment may have failed. If it persists, the portal would be unusable.'],
                default => ['A request to the database failed.', 'The page or task that made the request did not complete.'],
            };

            return [
                'fingerprint' => 'app|'.get_class($e).'|'.$state.'|'.basename($e->getFile()).':'.$e->getLine(), 'scope' => $scope, 'wrapper' => false, 'quiet' => 360,
                'title' => 'A database request failed', 'meaning' => $what, 'impact' => $impact, 'action' => $follow, 'technical' => $technical,
            ];
        }

        $class = $e::class;
        if (str_contains($class, 'Redis') || stripos($message, 'redis') !== false) {
            return [
                'fingerprint' => 'app|redis', 'scope' => $scope, 'wrapper' => false, 'quiet' => 120,
                'title' => 'The cache and queue service (Redis) had a problem', 'meaning' => 'The fast memory store that holds sessions and the job queue did not answer properly.',
                'impact' => 'People may have been logged out, or background jobs may have waited. It normally recovers by itself.',
                'action' => 'Nothing unless it repeats. The server monitor would flag a longer outage.', 'technical' => $technical,
            ];
        }

        if (str_contains($class, 'Transport') || stripos($message, 'smtp') !== false || stripos($message, 'mailer') !== false) {
            return [
                'fingerprint' => 'app|mail', 'scope' => $scope, 'wrapper' => false, 'quiet' => 360,
                'title' => 'An email could not be sent', 'meaning' => 'The email service (Brevo) refused or did not answer a message the system tried to send.',
                'impact' => 'One or more automatic emails may not have been delivered.',
                'action' => 'Check that the Brevo account is active and within its daily sending limit.', 'technical' => $technical,
            ];
        }

        return [
            'fingerprint' => 'app|'.$class.'|'.basename($e->getFile()).':'.$e->getLine(), 'scope' => $scope, 'wrapper' => false, 'quiet' => 360,
            'title' => 'Something unexpected went wrong in the application',
            'meaning' => 'A part of the portal hit a situation it was not built to handle and stopped what it was doing.',
            'impact' => 'The page or task involved did not complete. Other parts of the portal are not affected.',
            'action' => $follow, 'technical' => $technical,
        ];
    }

    /** "zoho:poll" when running as `php artisan zoho:poll`; null for web requests and queue workers. */
    public static function currentCommand(): ?string
    {
        if (! app()->runningInConsole()) {
            return null;
        }
        $cmd = $_SERVER['argv'][1] ?? null;

        return is_string($cmd) && ! str_starts_with($cmd, '-') ? mb_substr($cmd, 0, 100) : null;
    }
}
