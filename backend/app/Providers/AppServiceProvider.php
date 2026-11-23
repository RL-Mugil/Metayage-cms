<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\ComplianceItem;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\IpRecord;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Observers\UserObserver;
use App\Policies\ClientPolicy;
use App\Policies\ComplianceItemPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\InvoicePolicy;
use App\Policies\IpRecordPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\ReminderPolicy;
use App\Policies\TaskPolicy;
use App\Support\FirmContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(FirmContext::class, fn () => new FirmContext());
    }

    public function boot(): void
    {
        User::observe(UserObserver::class);

        // When a scheduled command finishes successfully, whatever it had been failing with is over (this is what lets
        // the system send a "recovered" email for a problem that was in the morning report).
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Console\Events\ScheduledTaskFinished::class, function (\Illuminate\Console\Events\ScheduledTaskFinished $event): void {
            if (($event->task->exitCode ?? 1) === 0 && is_string($event->task->command) && preg_match("~'artisan' ([^\s']+)~", $event->task->command, $m)) {
                $this->app->make(\App\Services\OpsIncidentService::class)->commandSucceeded($m[1]);
            }
        });

        // A queue job that exhausts its retries is recorded as a problem for the morning report (it sends no email itself).
        if ($this->app->environment('production')) {
            \Illuminate\Support\Facades\Queue::failing(function (\Illuminate\Queue\Events\JobFailed $event): void {
                $this->app->make(\App\Services\OpsAlertService::class)->alert(
                    'Queue job failed: '.$event->job->resolveName(),
                    $event->exception->getMessage().PHP_EOL.PHP_EOL."Connection: {$event->connectionName}, queue: ".$event->job->getQueue(),
                    'job|'.$event->job->resolveName(),
                    60,
                );
            });
        }

        // Never emit http:// asset/route URLs in production, even if a proxy hides the TLS hop.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Per-IP cap on the public enquiry form, on top of the route's burst limit.
        RateLimiter::for('public-contact', fn (Request $request) => Limit::perHour(10)->by($request->ip()));

        // Named limiters keep Zoho action limits separate from the general API counter.
        RateLimiter::for('zoho-refresh', fn (Request $request) => Limit::perMinute(10)->by($request->user()->id));
        RateLimiter::for('zoho-full-sync', fn (Request $request) => Limit::perHour(1)->by($request->user()->id));
        RateLimiter::for('integration-test', fn (Request $request) => Limit::perMinute(5)->by($request->user()->id));
        RateLimiter::for('zoho-read', fn (Request $request) => Limit::perMinute(30)->by($request->user()->id));
        RateLimiter::for('zoho-write', fn (Request $request) => Limit::perMinute(5)->by($request->user()->id));

        Gate::policy(Client::class,   ClientPolicy::class);
        Gate::policy(ComplianceItem::class, ComplianceItemPolicy::class);
        Gate::policy(Project::class,  ProjectPolicy::class);
        Gate::policy(Task::class,     TaskPolicy::class);
        Gate::policy(Invoice::class,  InvoicePolicy::class);
        Gate::policy(\App\Models\ZohoInvoice::class, \App\Policies\ZohoInvoicePolicy::class);
        Gate::policy(\App\Models\ZohoQuoteExport::class, \App\Policies\ZohoQuoteExportPolicy::class);
        Gate::policy(IpRecord::class, IpRecordPolicy::class);
        Gate::policy(Reminder::class, ReminderPolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(\App\Models\Inventor::class, \App\Policies\InventorPolicy::class);

        // Zoho Books → the portal's Indian Patents (UIN) register, the single source of truth:
        // every time the mirror saves a record (5-minute poll, nightly sync, manual link) or a
        // record's detail (line items) arrives, the register row is created or refreshed.
        $importZoho = function (\App\Models\ZohoInvoice $record): void {
            \Illuminate\Support\Facades\DB::afterCommit(fn () => rescue(
                fn () => app(\App\Services\ZohoRegisterImporter::class)->import($record->fresh() ?? \App\Models\ZohoInvoice::withTrashed()->find($record->id) ?? $record),
                null, true
            ));
        };
        \App\Models\ZohoInvoice::saved($importZoho);
        \App\Models\ZohoInvoice::deleted($importZoho);
        // Zoho customer payments → register payments (real dates) → the client's ledger.
        $importPayment = function (\App\Models\ZohoSnapshot $snapshot): void {
            \Illuminate\Support\Facades\DB::afterCommit(fn () => rescue(function () use ($snapshot) {
                $fresh = \App\Models\ZohoSnapshot::withTrashed()->find($snapshot->id) ?? $snapshot;
                $touched = app(\App\Services\RegisterPaymentsImporter::class)->importPayment($fresh);
                $clients = \App\Models\PatentInvoiceIn::whereIn('id', $touched)->whereNotNull('client_id')->distinct()->pluck('client_id');
                foreach ($clients as $client) {
                    app(\App\Services\ClientLedgerSync::class)->sync((int) $client);
                }
            }, null, true));
        };
        \App\Models\ZohoSnapshot::deleted(function (\App\Models\ZohoSnapshot $snapshot) use ($importPayment): void {
            if ($snapshot->resource === 'customerpayments') {
                $importPayment($snapshot);
            }
        });

        \App\Models\ZohoSnapshot::saved(function (\App\Models\ZohoSnapshot $snapshot) use ($importZoho, $importPayment): void {
            if ($snapshot->resource === 'customerpayments') {
                if ($snapshot->wasRecentlyCreated || $snapshot->wasChanged(['summary', 'detail'])) {
                    $importPayment($snapshot);
                }

                return;
            }
            if (! in_array($snapshot->resource, ['invoices', 'estimates'], true) || ! $snapshot->wasChanged('detail')) {
                return;
            }
            $record = \App\Models\ZohoInvoice::withTrashed()->where('scope', $snapshot->scope)
                ->where('zoho_type', $snapshot->resource === 'estimates' ? 'quote' : 'invoice')
                ->where('zoho_id', $snapshot->remote_id)->first();
            if ($record) {
                $importZoho($record);
            }
        });
        Gate::define('manage-site', fn (User $user): bool => $user->role === 'super_admin');
        Gate::define('approve-deadline-rules', fn (User $user): bool => in_array($user->role, ['super_admin', 'partner'], true));
        Gate::define('review-docket-deadline', fn (User $user, Project $project): bool =>
            in_array($user->role, ['super_admin', 'partner'], true)
            || (int) $project->docket_reviewer_id === (int) $user->id
        );

        // Horizon's dashboard exposes job/queue payloads (can include PII) — package
        // default already denies non-local environments with no gate defined, but
        // that's an implicit safety net; make the control explicit.
        Gate::define('viewHorizon', fn (User $user): bool => $user->role === 'super_admin');

        // Secondary per-email login throttle, on top of routes/web.php's existing
        // IP-keyed `throttle:10,1` — the IP throttle alone doesn't stop a distributed
        // attacker (botnet/proxy rotation) from brute-forcing one known email address.
        RateLimiter::for('login-email', function (Request $request) {
            return Limit::perMinute(5)->by(Str::lower((string) $request->input('email')) . '|login');
        });

        // Queue-health alerting beyond the generic exception-reporting webhook —
        // mail only (no Slack incoming-webhook URL configured in this app; the
        // existing SLACK_BOT_USER_OAUTH_TOKEN is a bot token, not compatible with
        // Horizon's routeSlackNotificationsTo(), which needs an incoming webhook
        // URL, so it's deliberately not wired here). No-op until HORIZON_ALERT_EMAIL
        // is set — safe to leave unconfigured.
        if ($alertEmail = env('HORIZON_ALERT_EMAIL')) {
            Horizon::routeMailNotificationsTo($alertEmail);
        }
    }
}
