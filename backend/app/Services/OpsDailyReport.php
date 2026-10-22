<?php

namespace App\Services;

use App\Mail\OpsReportMail;
use App\Models\OpsIncident;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;

/**
 * The one email a day about the health of the system, written for people who do not read logs.
 * Healthy or not, it goes out once each morning (06:00 IST). Problems are described in plain English.
 */
class OpsDailyReport
{
    public const SETTING = 'ops_last_daily_report';
    private const TZ = 'Asia/Kolkata';

    public function __construct(private OpsIncidentService $incidents)
    {
    }

    /**
     * Send today's report unless one has already gone out today (India time). Returns true if a mail was sent.
     */
    public function sendIfDue(bool $force = false): bool
    {
        $today = now(self::TZ)->toDateString();
        if (! $force && DB::table('system_settings')->where('key', self::SETTING)->value('value') === $today) {
            return false;
        }
        $to = app(OpsAlertService::class)->emailRecipients();
        if ($to === []) {
            return false;
        }

        $this->incidents->sweep(); // settle anything that already calmed down before writing the report
        $report = $this->build();
        Mail::to($to)->send(OpsReportMail::daily($report));

        // Remember what was reported: open items get a recovery email later; already-recovered ones are mentioned once here.
        OpsIncident::whereIn('id', $report['open_ids'])->update(['reported_at' => now()]);
        OpsIncident::whereIn('id', $report['resolved_ids'])->update(['reported_at' => now(), 'recovery_mailed_at' => now()]);
        $now = now();
        DB::table('system_settings')->upsert([['key' => self::SETTING, 'value' => $today, 'created_at' => $now, 'updated_at' => $now]], ['key'], ['value', 'updated_at']);

        return true;
    }

    /** @return array{subject: string, html: string, text: string, open_ids: array, resolved_ids: array, issues: int} */
    public function build(): array
    {
        $open = $this->incidents->open();
        $resolved = $this->incidents->recentlyResolved();
        $checks = $this->liveChecks();
        $failing = collect($checks)->where('ok', false)->values();
        $issues = $open->count() + $failing->count();
        $when = now(self::TZ);
        $date = $when->format('j M Y');

        $subject = $issues === 0
            ? "IPFlow daily health report, {$date}: all healthy"
            : "IPFlow daily health report, {$date}: ".$issues.($issues === 1 ? ' issue needs' : ' issues need').' attention';

        return [
            'subject' => $subject,
            'html' => $this->html($subject, $date, $open, $failing, $resolved, $checks),
            'text' => $this->text($subject, $open, $failing, $resolved, $checks),
            'open_ids' => $open->pluck('id')->all(),
            'resolved_ids' => $resolved->pluck('id')->all(),
            'issues' => $issues,
        ];
    }

    /**
     * What is working right now, in plain words. Each check is cheap and read-only.
     *
     * @return list<array{name: string, ok: bool, detail: string, meaning: string, impact: string, action: string}>
     */
    public function liveChecks(): array
    {
        $out = [];
        $add = function (string $name, bool $ok, string $detail, string $meaning = '', string $impact = '', string $action = '') use (&$out) {
            $out[] = compact('name', 'ok', 'detail', 'meaning', 'impact', 'action');
        };

        try {
            DB::select('select 1');
            $add('Database', true, 'Answering normally.');
        } catch (\Throwable) {
            $add('Database', false, 'Not answering.', 'The main database did not answer.', 'The portal cannot work without it.', 'Ask the developer to check the server immediately.');
        }

        try {
            Redis::connection()->ping();
            $add('Cache and job queue (Redis)', true, 'Answering normally.');
        } catch (\Throwable) {
            $add('Cache and job queue (Redis)', false, 'Not answering.', 'The cache and job queue service did not answer.', 'Logins and background jobs may fail.', 'Ask the developer to check the server.');
        }

        $beat = Cache::get('ops:scheduler:beat');
        $beatOk = $beat && now()->timestamp - (int) $beat < 300;
        $add('Automatic tasks (scheduler)', (bool) $beatOk, $beatOk ? 'Running every minute.' : 'No sign of life in the last few minutes.',
            'The part of the system that starts automatic tasks (reminders, Zoho updates, clean-ups) is not running.', 'None of those tasks will run until it is fixed.', 'Ask the developer to restart the scheduler.');

        $poll = DB::table('integration_logs')->where('slug', 'zoho')->where('event_type', 'poll')->where('status', 'ok')->max('created_at');
        $pollAge = $poll ? abs((int) now()->diffInMinutes(\Illuminate\Support\Carbon::parse($poll))) : null;
        $pollOk = $pollAge !== null && $pollAge < 60;
        $add('Zoho Books connection', $pollOk, $pollOk ? 'Last successful update check '.$this->ago((int) $pollAge).'.' : 'No successful update check in the last hour.',
            'The portal has not been able to check Zoho Books for changes for over an hour.', 'Invoice and quote figures in the portal may be out of date.', 'If it is still like this tomorrow, ask the developer to look at the Zoho connection.');

        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        $add('Background jobs', $failed < 5, $failed === 0 ? 'No failed jobs in the last 24 hours.' : "{$failed} failed in the last 24 hours.",
            'Several background jobs (emails, imports, syncs) failed in the last day.', 'Whatever those jobs do may not have happened.', 'Share this report with the developer.');

        $disk = @disk_free_space('/') && @disk_total_space('/') ? 100 - (int) round(disk_free_space('/') / disk_total_space('/') * 100) : null;
        if ($disk !== null) {
            $add('Server disk space', $disk < 90, "{$disk}% used.", 'The server\'s storage is nearly full.', 'New files and backups could fail to save.', 'Ask the developer to free up space.');
        }

        $dir = '/var/backups/mypl-cms-drive';
        $files = @glob($dir.'/mypl_cms_*.sql.gz.gpg') ?: [];
        if ($files) {
            usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
            $ageH = (time() - filemtime($files[0])) / 3600;
            $mb = round(filesize($files[0]) / 1048576, 1);
            $add('Database backup', $ageH < 8 && $mb > 1, 'Latest backup '.round($ageH, 1)." hours ago ({$mb} MB).",
                'The newest database backup is older than expected or unusually small.', 'If something went wrong, there may be no recent copy to restore from.', 'Ask the developer to check the backup job today.');
        }

        return $out;
    }

    private function ago(int $minutes): string
    {
        return $minutes < 1 ? 'just now' : ($minutes < 60 ? "{$minutes} minutes ago" : round($minutes / 60, 1).' hours ago');
    }

    private function stamp($time): string
    {
        return $time ? $time->copy()->timezone(self::TZ)->format('j M, g:i A') : '-';
    }

    // ---------------------------------------------------------------- rendering

    private function html(string $subject, string $date, Collection $open, Collection $failing, Collection $resolved, array $checks): string
    {
        $healthy = $open->isEmpty() && $failing->isEmpty();
        $banner = $healthy
            ? '<div style="background:#e8f7ef;border:1px solid #b9e4cd;color:#14633a;padding:16px 18px;border-radius:8px;font-size:16px"><strong>All healthy.</strong> Everything is working as expected. Nothing needs your attention today.</div>'
            : '<div style="background:#fff4e0;border:1px solid #f1d49a;color:#7a4b00;padding:16px 18px;border-radius:8px;font-size:16px"><strong>'.e(($open->count() + $failing->count()).' '.(($open->count() + $failing->count()) === 1 ? 'issue needs' : 'issues need')).' attention.</strong> Each one is explained below in plain English.</div>';

        $cards = '';
        foreach ($open as $i) {
            $cards .= $this->card($i->title, [
                ['What happened', $i->meaning], ['What it affects', $i->impact], ['What to do', $i->action],
            ], 'First noticed '.$this->stamp($i->first_seen_at).', last seen '.$this->stamp($i->last_seen_at).', seen '.$i->occurrences.($i->occurrences === 1 ? ' time' : ' times').'.', $i->technical);
        }
        foreach ($failing as $c) {
            $cards .= $this->card($c['name'].': '.$c['detail'], [['What happened', $c['meaning']], ['What it affects', $c['impact']], ['What to do', $c['action']]], 'Checked just now.', null);
        }
        $section = $healthy ? '' : '<h2 style="font-size:17px;margin:26px 0 10px;color:#212529">Needs attention</h2>'.$cards;

        $recovered = '';
        if ($resolved->isNotEmpty()) {
            $recovered = '<h2 style="font-size:17px;margin:26px 0 10px;color:#212529">Happened and fixed itself</h2><p style="margin:0 0 8px;color:#5c646c;font-size:14px">These came up since the last report and are working again. No action needed.</p><ul style="margin:0;padding-left:20px;color:#212529;font-size:14px;line-height:1.6">';
            foreach ($resolved as $i) {
                $recovered .= '<li><strong>'.e($i->title).'</strong> &mdash; '.e($i->meaning).' <span style="color:#5c646c">(back to normal '.e($this->stamp($i->resolved_at)).')</span></li>';
            }
            $recovered .= '</ul>';
        }

        $rows = '';
        foreach ($checks as $c) {
            $rows .= '<tr><td style="padding:8px 10px;border-bottom:1px solid #eceff1;font-size:14px">'.e($c['name']).'</td><td style="padding:8px 10px;border-bottom:1px solid #eceff1;font-size:14px;color:'.($c['ok'] ? '#14633a' : '#a33a00').'">'.($c['ok'] ? 'OK' : 'Needs attention').'</td><td style="padding:8px 10px;border-bottom:1px solid #eceff1;font-size:13px;color:#5c646c">'.e($c['detail']).'</td></tr>';
        }

        return '<!doctype html><html><body style="margin:0;background:#f4f6f8;font-family:Segoe UI,Arial,sans-serif;color:#212529"><div style="max-width:680px;margin:0 auto;padding:24px 16px">'
            .'<p style="margin:0 0 4px;color:#5c646c;font-size:12px;letter-spacing:.08em;text-transform:uppercase">IPFlow &middot; daily health report</p>'
            .'<h1 style="margin:0 0 16px;font-size:22px">'.e($date).'</h1>'.$banner.$section.$recovered
            .'<h2 style="font-size:17px;margin:26px 0 8px;color:#212529">What was checked just now</h2><table style="width:100%;border-collapse:collapse;background:#fff;border:1px solid #dfe3e6;border-radius:6px">'.$rows.'</table>'
            .'<p style="margin:26px 0 0;color:#6b747c;font-size:12px;line-height:1.6">You get this one email each morning at 6:00 AM, healthy or not. If a problem listed here clears up, you will get a separate email right away saying so. Errors during the day are collected quietly and appear in the next report; they do not send emails of their own.</p>'
            .'</div></body></html>';
    }

    private function card(string $title, array $rows, string $meta, ?string $technical): string
    {
        $body = '';
        foreach ($rows as [$label, $text]) {
            $body .= '<p style="margin:8px 0 0;font-size:14px;line-height:1.55"><strong style="color:#0f6ca8">'.e($label).':</strong> '.e($text).'</p>';
        }

        return '<div style="background:#fff;border:1px solid #dfe3e6;border-left:4px solid #e08a00;border-radius:6px;padding:14px 16px;margin:0 0 12px"><div style="font-size:16px;font-weight:600">'.e($title).'</div>'
            .'<div style="margin-top:2px;color:#6b747c;font-size:12px">'.e($meta).'</div>'.$body
            .($technical ? '<p style="margin:10px 0 0;color:#8a939b;font-size:11px;line-height:1.5;font-family:Consolas,monospace;word-break:break-word">For the developer: '.e($technical).'</p>' : '').'</div>';
    }

    private function text(string $subject, Collection $open, Collection $failing, Collection $resolved, array $checks): string
    {
        $t = $subject."\n\n";
        if ($open->isEmpty() && $failing->isEmpty()) {
            $t .= "All healthy. Everything is working as expected.\n\n";
        } else {
            $t .= "NEEDS ATTENTION\n";
            foreach ($open as $i) {
                $t .= "\n* {$i->title}\n  What happened: {$i->meaning}\n  What it affects: {$i->impact}\n  What to do: {$i->action}\n  First noticed ".$this->stamp($i->first_seen_at).', seen '.$i->occurrences." time(s).\n  For the developer: {$i->technical}\n";
            }
            foreach ($failing as $c) {
                $t .= "\n* {$c['name']}: {$c['detail']}\n  What happened: {$c['meaning']}\n  What it affects: {$c['impact']}\n  What to do: {$c['action']}\n";
            }
            $t .= "\n";
        }
        if ($resolved->isNotEmpty()) {
            $t .= "HAPPENED AND FIXED ITSELF\n";
            foreach ($resolved as $i) {
                $t .= "* {$i->title}: {$i->meaning}\n";
            }
            $t .= "\n";
        }
        $t .= "CHECKED JUST NOW\n";
        foreach ($checks as $c) {
            $t .= '* '.$c['name'].': '.($c['ok'] ? 'OK' : 'Needs attention').' ('.$c['detail'].")\n";
        }

        return $t."\nYou get this one email each morning at 6:00 AM. If a listed problem clears up you get a separate email right away.\n";
    }

    /** @return array{subject: string, html: string, text: string} */
    public function recoveryMail(Collection $incidents): array
    {
        $n = $incidents->count();
        $subject = $n === 1 ? 'IPFlow: recovered, '.$incidents->first()->title : "IPFlow: {$n} issues recovered";
        $items = '';
        $plain = '';
        foreach ($incidents as $i) {
            $items .= '<li style="margin:0 0 8px"><strong>'.e($i->title).'</strong><br><span style="color:#5c646c;font-size:13px">'.e($i->meaning).' It was first noticed '.e($this->stamp($i->first_seen_at)).' and is back to normal as of '.e($this->stamp($i->resolved_at)).'.</span></li>';
            $plain .= "* {$i->title} - back to normal as of ".$this->stamp($i->resolved_at)."\n";
        }
        $html = '<!doctype html><html><body style="margin:0;background:#f4f6f8;font-family:Segoe UI,Arial,sans-serif;color:#212529"><div style="max-width:620px;margin:0 auto;padding:24px 16px">'
            .'<div style="background:#e8f7ef;border:1px solid #b9e4cd;color:#14633a;padding:16px 18px;border-radius:8px;font-size:16px"><strong>Good news: '.($n === 1 ? 'a problem from this morning\'s report has recovered.' : 'problems from this morning\'s report have recovered.').'</strong> Nothing for you to do.</div>'
            .'<ul style="margin:18px 0 0;padding-left:20px;font-size:14px;line-height:1.55">'.$items.'</ul>'
            .'<p style="margin:22px 0 0;color:#6b747c;font-size:12px">The next normal report arrives tomorrow at 6:00 AM.</p></div></body></html>';

        return ['subject' => $subject, 'html' => $html, 'text' => "Good news: recovered.\n\n".$plain."\nNothing for you to do. The next report arrives tomorrow at 6:00 AM.\n"];
    }
}
