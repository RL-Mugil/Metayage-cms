<?php

namespace App\Services;

use App\Http\Controllers\PayslipPdfController;
use App\Http\Controllers\ReportsController;
use App\Models\AuditLog;
use App\Models\Payslip;
use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download links for files Claude produces (reports, payslips, calendars). The user chose
 * secret links that work without signing in for 30 minutes; only a hash of the token is
 * stored, and every download is audit-logged. Files are rendered on demand, as the user who
 * asked, through the same code the portal pages use.
 */
class ClaudeFiles
{
    public const TTL_MINUTES = 30;

    public const MIME = [
        'pdf' => 'application/pdf',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv' => 'text/csv',
        'ics' => 'text/calendar',
    ];

    /** @return array{url: string, expires_at: string, file: string, mime: string} */
    public function link(User $user, string $kind, ?int $refId, string $file, array $params = []): array
    {
        $token = Str::random(48);
        DB::table('claude_file_links')->insert([
            'token_hash' => hash('sha256', $token), 'user_id' => $user->id, 'kind' => $kind, 'ref_id' => $refId, 'file' => $file,
            'params' => json_encode($params), 'expires_at' => now()->addMinutes(self::TTL_MINUTES), 'downloads' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'url' => rtrim((string) config('app.url'), '/').'/files/claude/'.$token,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->timezone('Asia/Kolkata')->format('d M Y, H:i').' IST',
            'file' => $file, 'mime' => self::MIME[$file] ?? 'application/octet-stream',
        ];
    }

    /** The file behind a token, or the HTTP status to return (404 unknown, 410 expired). */
    public function serve(string $token, Request $incoming): Response
    {
        $row = DB::table('claude_file_links')->where('token_hash', hash('sha256', $token))->first();
        if (! $row) {
            return response('This download link is not valid.', 404, ['Content-Type' => 'text/plain']);
        }
        if (now()->greaterThan($row->expires_at)) {
            return response('This download link has expired. Ask Claude for a new one.', 410, ['Content-Type' => 'text/plain']);
        }
        $user = User::find($row->user_id);
        if (! $user || ($user->status ?? 'Active') !== 'Active') {
            return response('This download link is no longer valid.', 410, ['Content-Type' => 'text/plain']);
        }

        @ini_set('memory_limit', '512M');
        // Same firm scoping as the portal pages, as the user who asked for the file.
        $incoming->setUserResolver(fn () => $user);

        return app(\App\Http\Middleware\ResolveFirmContext::class)->handle($incoming, fn () => $this->deliver($row, $user, $incoming));
    }

    private function deliver(object $row, User $user, Request $incoming): Response
    {
        [$bytes, $name, $mime] = match ($row->kind) {
            'report' => $this->report($user, (int) $row->ref_id, $row->file),
            'payslip' => $this->payslip($user, (int) $row->ref_id),
            'ics' => $this->ics($user, (array) json_decode((string) $row->params, true)),
            default => abort(404),
        };

        DB::table('claude_file_links')->where('id', $row->id)->update(['downloads' => DB::raw('downloads + 1'), 'last_downloaded_at' => now(), 'updated_at' => now()]);
        AuditLog::create([
            'user_id' => $user->id, 'action' => 'claude_file_download', 'subject_type' => ucfirst($row->kind), 'subject_id' => $row->ref_id,
            'metadata' => ['file' => $row->file, 'name' => $name], 'ip_address' => $incoming->ip(), 'user_agent' => mb_substr((string) $incoming->userAgent(), 0, 255),
        ]);

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $name).'"',
            'Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /** A saved report re-rendered from its snapshot, exactly like Reports → History → Download. */
    private function report(User $user, int $exportId, string $file): array
    {
        $export = ReportExport::findOrFail($exportId);
        $owner = (int) $export->generated_by_id === (int) $user->id ? $user : User::findOrFail($export->generated_by_id);
        $request = Request::create('/api/reports/history/'.$exportId.'/download', 'POST', ['file' => $file]);
        $request->setUserResolver(fn () => $owner);
        $response = app(ReportsController::class)->downloadHistory($request, $exportId,
            app(\App\Services\Reports\PaymentReportData::class), app(\App\Services\Reports\ReportSigner::class));

        return [$this->bytes($response), $this->filename($response, "report-{$exportId}.{$file}"), self::MIME[$file] ?? 'application/octet-stream'];
    }

    /** The user's own signed payslip. */
    private function payslip(User $user, int $payslipId): array
    {
        $slip = Payslip::with(['components', 'employee.designation', 'run:id,period,status,run_type', 'internalSignature.signedBy'])->findOrFail($payslipId);
        abort_unless((int) $slip->employee?->user_id === (int) $user->id, 404);
        abort_unless(app(\App\Services\Payroll\PayslipSigner::class)->isSigned($slip), 404);

        return [app(PayslipPdfController::class)->rawMonthlyPdfOutput($slip), "payslip-{$slip->employee_code}-{$slip->run->period->format('Y-m')}.pdf", self::MIME['pdf']];
    }

    /** Deadlines as an iCalendar file (all-day events). */
    private function ics(User $user, array $params): array
    {
        $days = max(1, min(365, (int) ($params['days'] ?? 90)));
        $deadlines = app(\App\Ai\Tools\UpcomingDeadlines::class)->handle($user, ['days' => $days, 'include_overdue' => false, 'limit' => 50])['deadlines'];
        $esc = fn (string $s) => str_replace(["\\", ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $s);
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//MyIPStrategy//Portal deadlines//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:MyIPStrategy deadlines'];
        foreach ($deadlines as $i => $d) {
            $date = str_replace('-', '', (string) $d['date']);
            $lines = [...$lines, 'BEGIN:VEVENT', 'UID:'.md5($user->id.'|'.$i.'|'.$d['date'].'|'.$d['title'].'|'.$d['docket_number']).'@myipstrategy',
                'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'), "DTSTART;VALUE=DATE:{$date}",
                'SUMMARY:'.$esc(trim(($d['docket_number'] ? $d['docket_number'].' - ' : '').$d['title'])),
                'DESCRIPTION:'.$esc(trim(($d['case'] ?? '').' ('.$d['kind'].')')),
                'BEGIN:VALARM', 'TRIGGER:-P3D', 'ACTION:DISPLAY', 'DESCRIPTION:Deadline in 3 days', 'END:VALARM', 'END:VEVENT'];
        }
        $lines[] = 'END:VCALENDAR';

        return [implode("\r\n", $lines)."\r\n", 'myipstrategy-deadlines.ics', self::MIME['ics']];
    }

    private function bytes(Response $response): string
    {
        abort_unless($response->getStatusCode() === 200, $response->getStatusCode());
        if ($response instanceof StreamedResponse) {
            ob_start();
            $response->sendContent();

            return (string) ob_get_clean();
        }

        return (string) $response->getContent();
    }

    private function filename(Response $response, string $fallback): string
    {
        return preg_match('/filename="?([^";]+)"?/', (string) $response->headers->get('Content-Disposition'), $m) ? $m[1] : $fallback;
    }
}
