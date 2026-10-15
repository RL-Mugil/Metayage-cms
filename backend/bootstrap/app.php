<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\RestrictClientPages::class,
            \App\Http\Middleware\EnforceMaintenanceMode::class,
        ]);
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);
        $middleware->api(append: [
            \App\Http\Middleware\EnsureUserIsActive::class,
            \App\Http\Middleware\EnsureClientPortalEnabled::class,
            \App\Http\Middleware\EnforceMaintenanceMode::class,
        ]);
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'firm.context' => \App\Http\Middleware\ResolveFirmContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API routes always return JSON on error. (Avoid calling the Inertia
        // request macro here — it isn't registered on the api middleware group.)
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Forward unhandled exceptions to a monitoring webhook (e.g. a
        // self-hosted n8n workflow → GlitchTip/email). No-op when unset.
        // Best-effort and fully swallowed so monitoring can never break a request.
        // Tell the system admins and directors (in-app + email) about unhandled errors. Production only, and
        // de-duplicated per error location so a storm sends one alert per 15 minutes.
        $exceptions->report(function (\Throwable $e): void {
            if (! app()->environment('production')) {
                return;
            }
            try {
                app(\App\Services\OpsAlertService::class)->alert(
                    'Server error: '.class_basename($e),
                    $e->getMessage().PHP_EOL.PHP_EOL.$e->getFile().':'.$e->getLine().PHP_EOL.request()->method().' '.request()->fullUrl().PHP_EOL.now()->toDateTimeString().' (server time)',
                    $e::class.'|'.$e->getFile().':'.$e->getLine(),
                );
            } catch (\Throwable) {
                // never let alerting failures surface
            }
        });

        $exceptions->report(function (\Throwable $e): void {
            $url = config('services.monitoring.webhook');
            if (! $url) {
                return;
            }
            try {
                \Illuminate\Support\Facades\Http::timeout(3)->post($url, [
                    'app'       => 'mypl-cms',
                    'env'       => app()->environment(),
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                    'file'      => $e->getFile().':'.$e->getLine(),
                    'url'       => request()->fullUrl(),
                    'method'    => request()->method(),
                    'user_id'   => optional(request()->user())->id,
                    'at'        => now()->toIso8601String(),
                ]);
            } catch (\Throwable) {
                // never let monitoring failures surface
            }
        });
    })->create();
