<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\HRMSController;
use App\Http\Controllers\FinancialController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AIController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProjectTrackerController;
use App\Http\Controllers\ReportsController;

// Public routes
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// Authenticated routes protected by Sanctum
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/users', [AuthController::class, 'users']);

    // Dashboard
    Route::get('/dashboard/metrics', [DashboardController::class, 'metrics']);

    // CRM / Clients
    Route::get('/clients', [ClientController::class, 'index']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::get('/clients/{id}', [ClientController::class, 'show']);
    Route::put('/clients/{id}', [ClientController::class, 'update']);
    Route::delete('/clients/{id}', [ClientController::class, 'destroy']);
    Route::post('/clients/{id}/contacts', [ClientController::class, 'addContact']);

    // Cases / Projects
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{id}', [ProjectController::class, 'show']);
    Route::put('/projects/{id}', [ProjectController::class, 'update']);
    Route::delete('/projects/{id}', [ProjectController::class, 'destroy']);
    Route::post('/projects/{id}/stage', [ProjectController::class, 'updateStage']);

    // Tasks & Time tracking
    Route::get('/tasks', [TaskController::class, 'index']);
    Route::post('/tasks', [TaskController::class, 'store']);
    Route::put('/tasks/{id}', [TaskController::class, 'update']);
    Route::delete('/tasks/{id}', [TaskController::class, 'destroy']);
    Route::post('/tasks/time-entries', [TaskController::class, 'addTimeEntry']);

    // Enterprise HRMS
    Route::get('/hrms/employees', [HRMSController::class, 'employees']);
    Route::post('/hrms/employees', [HRMSController::class, 'createEmployee']);
    Route::put('/hrms/employees/{id}', [HRMSController::class, 'updateEmployee']);
    Route::delete('/hrms/employees/{id}', [HRMSController::class, 'deleteEmployee']);
    Route::get('/hrms/attendance', [HRMSController::class, 'attendance']);
    Route::post('/hrms/clock-in', [HRMSController::class, 'clockIn']);
    Route::post('/hrms/clock-out', [HRMSController::class, 'clockOut']);
    Route::get('/hrms/leaves', [HRMSController::class, 'leaves']);
    Route::post('/hrms/leaves', [HRMSController::class, 'applyLeave']);
    Route::put('/hrms/leaves/{id}', [HRMSController::class, 'updateLeave']);

    // Financial Suite
    Route::get('/financial/invoices', [FinancialController::class, 'invoices']);
    Route::post('/financial/invoices', [FinancialController::class, 'createInvoice']);
    Route::put('/financial/invoices/{id}', [FinancialController::class, 'updateInvoice']);
    Route::delete('/financial/invoices/{id}', [FinancialController::class, 'deleteInvoice']);
    Route::get('/financial/quotations', [FinancialController::class, 'quotations']);
    Route::post('/financial/payments', [FinancialController::class, 'recordPayment']);

    // Reports
    Route::get('/reports/data', [ReportsController::class, 'getData']);

    // AI Chat
    Route::post('/ai/query', [AIController::class, 'query']);

    // Calendar
    Route::get('/calendar/events', [ProjectTrackerController::class, 'calendarEvents']);

    // Analytics
    Route::get('/analytics/tracker', [ProjectTrackerController::class, 'trackerAnalytics']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'dismiss']);

    // Project Tracker
    Route::get('/tracker/projects', [ProjectTrackerController::class, 'projects']);
    Route::get('/tracker/circles', [ProjectTrackerController::class, 'circles']);
    Route::post('/tracker/circles/{id}/members', [ProjectTrackerController::class, 'addMember']);
    Route::delete('/tracker/circles/{id}/members/{userId}', [ProjectTrackerController::class, 'removeMember']);
    Route::get('/tracker/rows', [ProjectTrackerController::class, 'rows']);
    Route::post('/tracker/rows', [ProjectTrackerController::class, 'createRow']);
    Route::put('/tracker/rows/{id}', [ProjectTrackerController::class, 'updateRow']);
    Route::delete('/tracker/rows/{id}', [ProjectTrackerController::class, 'deleteRow']);

    // Documents
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::post('/documents', [DocumentController::class, 'store']);
    Route::delete('/documents', [DocumentController::class, 'destroy']);
    Route::get('/documents/download', [DocumentController::class, 'download']);

    // Approvals
    Route::get('/approvals', [\App\Http\Controllers\ApprovalController::class, 'index']);
    Route::post('/approvals/resolve', [\App\Http\Controllers\ApprovalController::class, 'resolve']);

    // Discussions
    Route::get('/discussions', [\App\Http\Controllers\DiscussionController::class, 'index']);
    Route::post('/discussions', [\App\Http\Controllers\DiscussionController::class, 'store']);
    Route::post('/discussions/{id}/reply', [\App\Http\Controllers\DiscussionController::class, 'reply']);

    // Settings
    Route::put('/settings/profile', [\App\Http\Controllers\SettingsController::class, 'updateProfile']);
    Route::put('/settings/password', [\App\Http\Controllers\SettingsController::class, 'updatePassword']);

    // Payroll
    Route::get('/payroll/runs', [\App\Http\Controllers\PayrollController::class, 'index']);
    Route::post('/payroll/runs', [\App\Http\Controllers\PayrollController::class, 'store']);
    Route::get('/payroll/runs/{id}', [\App\Http\Controllers\PayrollController::class, 'show']);
    Route::delete('/payroll/runs/{id}', [\App\Http\Controllers\PayrollController::class, 'destroy']);
    Route::post('/payroll/runs/{id}/finalize', [\App\Http\Controllers\PayrollController::class, 'finalize']);
    Route::post('/payroll/runs/{id}/pay', [\App\Http\Controllers\PayrollController::class, 'markPaid']);
    Route::put('/payroll/payslips/{id}', [\App\Http\Controllers\PayrollController::class, 'updatePayslip']);
    Route::get('/payroll/my-slips', [\App\Http\Controllers\PayrollController::class, 'mySlips']);
});
