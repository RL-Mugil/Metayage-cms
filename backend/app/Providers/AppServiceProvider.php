<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Task;
use App\Policies\ClientPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\InvoicePolicy;
use App\Policies\ProjectPolicy;
use App\Policies\TaskPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Gate::policy(Client::class,   ClientPolicy::class);
        Gate::policy(Project::class,  ProjectPolicy::class);
        Gate::policy(Task::class,     TaskPolicy::class);
        Gate::policy(Invoice::class,  InvoicePolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);
    }
}
