<?php

namespace App\Console\Commands;

use App\Mail\WeeklyDigestMail;
use App\Models\DocketDeadline;
use App\Models\PatentApplication;
use App\Models\RegisterPayment;
use App\Models\User;
use App\Services\FinanceFigures;
use App\Services\RenewalOutlook;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Monday digest to partners, directors, managers and super admins: overdue deadlines,
 * overdue payments, renewals due in 30 days, money collected last week. Off until a super
 * admin turns it on (system_settings.weekly_digest_enabled).
 */
class SendWeeklyDigest extends Command
{
    public const SETTING = 'weekly_digest_enabled';

    protected $signature = 'digest:weekly {--force : Send even when the digest is turned off}';

    protected $description = 'Email the weekly firm digest to partners and managers';

    public static function enabled(): bool
    {
        return \App\Http\Controllers\FeatureSwitchController::on(self::SETTING);
    }

    public function handle(FinanceFigures $figures): int
    {
        if (! self::enabled() && ! $this->option('force')) {
            $this->info('Weekly digest is turned off.');

            return self::SUCCESS;
        }
        // Each person can still opt out in Settings → Notifications (Weekly Digest, on unless turned off).
        $recipients = User::whereIn('role', ['super_admin', 'partner', 'director', 'manager'])->where('status', 'Active')->whereNotNull('email')->get()
            ->filter(fn (User $u) => ($u->notification_prefs['weeklyDigest'] ?? true) !== false);
        $sent = 0;
        foreach ($recipients as $user) {
            try {
                Mail::to($user->email)->send(new WeeklyDigestMail($user->name, $this->build($user, $figures)));
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Weekly digest failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }
        $this->info("Weekly digest sent to {$sent} people.");

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    public function build(User $user, FinanceFigures $figures): array
    {
        $base = rtrim((string) config('app.url'), '/');
        $today = now()->startOfDay();

        $deadlines = DocketDeadline::where('status', 'Open')->whereDate('due_date', '<', $today)->with('project:id,docket_number')->orderBy('due_date');
        $overdueRows = $figures->rows($user)->filter(fn ($r) => $r['outstanding'] > 0 && $r['due_date'] && $r['due_date']->lt($today))->sortBy(fn ($r) => $r['due_date']->timestamp);
        $renewals = RenewalOutlook::nextDue(PatentApplication::query()->select('id'), 2000)
            ->filter(fn ($r) => $r['days'] >= 0 && $r['days'] <= 30);

        return [
            'period' => now()->format('j M').' – '.now()->addDays(6)->format('j M Y'),
            'dashboard_url' => $base.'/',
            'overdue_deadlines' => [
                'count' => (clone $deadlines)->count(),
                'items' => (clone $deadlines)->limit(10)->get()->map(fn ($d) => [
                    'ref' => $d->project?->docket_number ?? '—', 'what' => $d->title,
                    'when' => \Carbon\Carbon::parse($d->due_date)->format('j M'),
                    'url' => $d->project_id ? "{$base}/projects/{$d->project_id}?tab=deadlines" : $base,
                ])->all(),
            ],
            'overdue_payments' => [
                'count' => $overdueRows->count(),
                'total' => round($overdueRows->sum('outstanding')),
                'items' => $overdueRows->take(10)->map(fn ($r) => [
                    'ref' => $r['number'] ?? '—', 'what' => '₹'.number_format($r['outstanding']).' outstanding',
                    'when' => (int) $r['due_date']->diffInDays($today).'d overdue',
                    'url' => $base.'/pending-payments?search='.rawurlencode((string) $r['number']),
                ])->values()->all(),
            ],
            'renewals' => [
                'count' => $renewals->count(),
                'items' => $renewals->take(10)->map(fn ($r) => [
                    'ref' => $r['docket_number'] ?? '—', 'what' => $r['label'],
                    'when' => \Carbon\Carbon::parse($r['due_date'])->format('j M'),
                    'url' => $r['id'] ? "{$base}/projects/{$r['id']}?tab=deadlines" : $base,
                ])->values()->all(),
            ],
            'collected_last_week' => round((float) RegisterPayment::cash()->whereBetween('payment_date', [now()->subDays(7)->toDateString(), now()->toDateString()])->sum('amount_bcy')),
        ];
    }
}
