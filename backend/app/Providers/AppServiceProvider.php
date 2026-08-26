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

        Gate::policy(Client::class,   ClientPolicy::class);
        Gate::policy(ComplianceItem::class, ComplianceItemPolicy::class);
        Gate::policy(Project::class,  ProjectPolicy::class);
        Gate::policy(Task::class,     TaskPolicy::class);
        Gate::policy(Invoice::class,  InvoicePolicy::class);
        Gate::policy(IpRecord::class, IpRecordPolicy::class);
        Gate::policy(Reminder::class, ReminderPolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);
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
