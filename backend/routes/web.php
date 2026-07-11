<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GoogleCalendarController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinancialController;
use App\Http\Controllers\HRMSController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectTrackerController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Auth routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.post');

    // Password reset
    Route::get('/forgot-password', [\App\Http\Controllers\Auth\PasswordResetController::class, 'showLinkRequest'])->name('password.request');
    Route::post('/forgot-password', [\App\Http\Controllers\Auth\PasswordResetController::class, 'sendLink'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [\App\Http\Controllers\Auth\PasswordResetController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [\App\Http\Controllers\Auth\PasswordResetController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Protected app pages
Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/clients', [ClientController::class, 'inertiaIndex'])->name('clients.index');
    Route::get('/clients/{id}', [ClientController::class, 'inertiaShow'])->name('clients.show');
    Route::get('/projects', [ProjectController::class, 'inertiaIndex'])->name('projects.index');
    Route::get('/projects/{id}', [ProjectController::class, 'inertiaShow'])->name('projects.show');
    Route::get('/tasks', [TaskController::class, 'inertiaIndex'])->name('tasks.index');
    Route::get('/kanban', fn () => Inertia::render('Kanban'))->name('kanban');
    Route::get('/project-tracker', [ProjectTrackerController::class, 'inertiaIndex'])->name('project-tracker');
    Route::get('/calendar', fn () => Inertia::render('Calendar'))->name('calendar');
    Route::get('/financial', [FinancialController::class, 'inertiaIndex'])->name('financial.index');
    Route::get('/documents', fn () => Inertia::render('Documents'))->name('documents');
    Route::get('/ai', fn () => Inertia::render('AI'))->name('ai');
    Route::get('/analytics', fn () => Inertia::render('Analytics'))->name('analytics');
    Route::get('/reports', fn () => Inertia::render('Reports'))->name('reports');
    Route::get('/team', fn () => Inertia::render('Team'))->name('team');
    Route::get('/portal', fn () => Inertia::render('Portal'))->name('portal');
    Route::get('/discussions', fn () => Inertia::render('Discussions'))->name('discussions');
    Route::get('/approvals', fn () => Inertia::render('Approvals'))->name('approvals');
    Route::get('/feedback', fn () => Inertia::render('Feedback'))->name('feedback');
    Route::get('/reminders', fn () => Inertia::render('Reminders'))->name('reminders');
    Route::get('/notifications', fn () => Inertia::render('Notifications'))->name('notifications');
    Route::get('/bulk', fn () => Inertia::render('Bulk'))->name('bulk');
    Route::get('/compliance', fn () => Inertia::render('Compliance'))->name('compliance');
    Route::get('/integrations', fn () => Inertia::render('Integrations'))->name('integrations');
    Route::get('/settings', fn () => Inertia::render('Settings'))->name('settings');
    Route::get('/patent-portfolio', fn () => Inertia::render('PatentPortfolio'))->name('patent-portfolio');
    Route::get('/patent-lifecycle', fn () => Inertia::render('PatentLifecycle'))->name('patent-lifecycle');
    Route::get('/portal-users', fn () => Inertia::render('PortalUsers'))->name('portal-users');
    Route::get('/staff-users', fn () => Inertia::render('StaffUsers'))->name('staff-users');

    // Google Calendar OAuth
    Route::get('/integrations/google/connect', [GoogleCalendarController::class, 'connect'])->name('gcal.connect');
    Route::get('/integrations/google/disconnect', [GoogleCalendarController::class, 'disconnect'])->name('gcal.disconnect');

    // HRMS sub-routes
    Route::get('/hrms', [HRMSController::class, 'inertiaIndex'])->name('hrms.index');
    Route::get('/hrms/employees', [HRMSController::class, 'inertiaEmployees'])->name('hrms.employees');
    Route::get('/hrms/attendance', [HRMSController::class, 'inertiaAttendance'])->name('hrms.attendance');
    Route::get('/hrms/leave', fn () => Inertia::render('HRMS/Leave'))->name('hrms.leave');
    Route::get('/hrms/payroll', fn () => Inertia::render('HRMS/Payroll'))->name('hrms.payroll');
    Route::get('/hrms/performance', fn () => Inertia::render('HRMS/Performance'))->name('hrms.performance');
    Route::get('/hrms/recruitment', fn () => Inertia::render('HRMS/Recruitment'))->name('hrms.recruitment');
    Route::get('/hrms/offboarding', fn () => Inertia::render('HRMS/Offboarding'))->name('hrms.offboarding');
});

// Google Calendar OAuth callback — auth middleware included, session already present
Route::get('/integrations/google/callback', [GoogleCalendarController::class, 'callback'])
    ->middleware('auth')
    ->name('gcal.callback');

// Horizon dashboard (admin only)
Route::middleware(['auth'])->group(function () {
    Route::get('/horizon', function () {
        return redirect('/horizon/dashboard');
    });
});
