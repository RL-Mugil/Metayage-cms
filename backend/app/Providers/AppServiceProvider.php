<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Observers\UserObserver;
use App\Policies\ClientPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\InvoicePolicy;
use App\Policies\ProjectPolicy;
use App\Policies\TaskPolicy;
use App\Support\FirmContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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
        Gate::policy(Project::class,  ProjectPolicy::class);
        Gate::policy(Task::class,     TaskPolicy::class);
        Gate::policy(Invoice::class,  InvoicePolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::define('approve-deadline-rules', fn (User $user): bool => in_array($user->role, ['super_admin', 'partner'], true));
        Gate::define('review-docket-deadline', fn (User $user, Project $project): bool =>
            in_array($user->role, ['super_admin', 'partner'], true)
            || (int) $project->docket_reviewer_id === (int) $user->id
        );
    }
}
