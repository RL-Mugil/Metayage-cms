<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\HRMSController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\FinancialController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AIController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProjectTrackerController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\PatentPortfolioController;
use App\Http\Controllers\SearchController;

// Login happens through the web route (session-based, see routes/web.php).
// The old public POST /api/login pointed at the same session login and
// always 500'd outside a browser — removed.

// Public webhook receiver — no auth, for external integrations
Route::post('/webhooks/{slug}', [\App\Http\Controllers\WebhookController::class, 'receive'])->middleware('throttle:120,1');

// Authenticated routes protected by Sanctum
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/users', [AuthController::class, 'users']);
    Route::put('/users/{id}/reset-password', [\App\Http\Controllers\SettingsController::class, 'resetUserPassword']);

    // Staff user administration (system admin only)
    Route::get('/staff-users', [\App\Http\Controllers\StaffUserController::class, 'index']);
    Route::post('/staff-users', [\App\Http\Controllers\StaffUserController::class, 'store']);
    Route::put('/staff-users/{id}', [\App\Http\Controllers\StaffUserController::class, 'update']);
    Route::delete('/staff-users/{id}', [\App\Http\Controllers\StaffUserController::class, 'destroy']);

    // Global search
    Route::get('/search', [SearchController::class, 'search'])->middleware('throttle:30,1');

    // Dashboard
    Route::get('/dashboard/metrics', [DashboardController::class, 'metrics']);

    // CRM / Clients
    Route::get('/clients/stats', [ClientController::class, 'stats']);
    Route::post('/clients/import', [ClientController::class, 'import'])->middleware('throttle:5,1');
    Route::get('/clients', [ClientController::class, 'index']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::get('/clients/{id}', [ClientController::class, 'show']);
    Route::put('/clients/{id}', [ClientController::class, 'update']);
    Route::delete('/clients/{id}', [ClientController::class, 'destroy']);
    Route::post('/clients/{id}/contacts', [ClientController::class, 'addContact']);

    // Cases / Projects
    Route::get('/projects/stats', [ProjectController::class, 'stats']);
    Route::get('/projects/lifecycle-stats', [ProjectController::class, 'lifecycleStats']);
    Route::get('/projects/import-template', [\App\Http\Controllers\ProjectImportController::class, 'template']);
    Route::post('/projects/import', [\App\Http\Controllers\ProjectImportController::class, 'import'])->middleware('throttle:10,1');
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
    Route::get('/hrms/stats', [HRMSController::class, 'stats']);
    Route::get('/hrms/employees', [HRMSController::class, 'employees']);
    Route::get('/hrms/employees/workload', [HRMSController::class, 'employeeWorkload']);
    Route::post('/hrms/employees', [HRMSController::class, 'createEmployee']);
    Route::post('/hrms/invitations', [HRMSController::class, 'inviteMember']);
    Route::put('/hrms/employees/{id}', [HRMSController::class, 'updateEmployee']);
    Route::delete('/hrms/employees/{id}', [HRMSController::class, 'deleteEmployee']);
    Route::get('/hrms/attendance', [AttendanceController::class, 'index']);
    Route::post('/hrms/clock-in', [AttendanceController::class, 'clockIn']);
    Route::post('/hrms/clock-out', [AttendanceController::class, 'clockOut']);
    // Admin attendance management
    Route::get('/hrms/attendance/settings', [AttendanceController::class, 'getSettings']);
    Route::put('/hrms/attendance/settings', [AttendanceController::class, 'updateSettings']);
    Route::get('/hrms/admin-attendance', [AttendanceController::class, 'adminIndex']);
    Route::post('/hrms/admin-attendance', [AttendanceController::class, 'adminStore']);
    Route::put('/hrms/admin-attendance/{id}', [AttendanceController::class, 'adminUpdate']);
    Route::delete('/hrms/admin-attendance/{id}', [AttendanceController::class, 'adminDestroy']);
    Route::get('/hrms/attendance/report', [AttendanceController::class, 'report']);
    Route::post('/hrms/employees/{id}/reset-today', [AttendanceController::class, 'resetToday']);
    Route::get('/hrms/leaves', [LeaveController::class, 'index']);
    Route::post('/hrms/leaves', [LeaveController::class, 'store']);
    Route::put('/hrms/leaves/{id}', [LeaveController::class, 'update']);

    // Financial Suite
    Route::get('/financial/stats', [FinancialController::class, 'stats']);
    Route::get('/financial/invoices', [FinancialController::class, 'invoices']);
    Route::post('/financial/invoices', [FinancialController::class, 'createInvoice']);
    Route::put('/financial/invoices/{id}', [FinancialController::class, 'updateInvoice']);
    Route::delete('/financial/invoices/{id}', [FinancialController::class, 'deleteInvoice']);
    Route::get('/financial/quotations', [FinancialController::class, 'quotations']);
    Route::post('/financial/payments', [FinancialController::class, 'recordPayment']);

    // Reports
    Route::get('/reports/data', [ReportsController::class, 'getData']);
    Route::post('/reports/generate', [ReportsController::class, 'generate']);
    Route::get('/reports/history', [ReportsController::class, 'history']);
    Route::get('/reports/history/{id}', [ReportsController::class, 'showHistory']);

    // Patent Portfolio
    Route::get('/patent-portfolio/stats', [PatentPortfolioController::class, 'stats']);

    // My Portal (client_admin self-service user management)
    Route::get('/my-portal/users', [\App\Http\Controllers\MyPortalController::class, 'users']);
    Route::post('/my-portal/users', [\App\Http\Controllers\MyPortalController::class, 'store']);
    Route::delete('/my-portal/users/{userId}', [\App\Http\Controllers\MyPortalController::class, 'destroy']);

    // AI Chat — stricter limit: 10 requests per minute
    Route::post('/ai/query', [AIController::class, 'query'])->middleware('throttle:10,1');

    // Calendar
    Route::get('/calendar/events', [ProjectTrackerController::class, 'calendarEvents']);

    // Analytics
    Route::get('/analytics/tracker', [ProjectTrackerController::class, 'trackerAnalytics']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
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
    Route::post('/approvals', [\App\Http\Controllers\ApprovalController::class, 'store']);
    Route::post('/approvals/resolve', [\App\Http\Controllers\ApprovalController::class, 'resolve']);

    // Discussions
    Route::get('/discussions', [\App\Http\Controllers\DiscussionController::class, 'index']);
    Route::post('/discussions', [\App\Http\Controllers\DiscussionController::class, 'store']);
    Route::post('/discussions/{id}/reply', [\App\Http\Controllers\DiscussionController::class, 'reply']);
    Route::put('/discussions/{id}', [\App\Http\Controllers\DiscussionController::class, 'update']);
    Route::delete('/discussions/{id}', [\App\Http\Controllers\DiscussionController::class, 'destroy']);

    // Settings
    Route::get('/settings',               [\App\Http\Controllers\SettingsController::class, 'getSettings']);
    Route::put('/settings/profile',       [\App\Http\Controllers\SettingsController::class, 'updateProfile']);
    Route::put('/settings/password',      [\App\Http\Controllers\SettingsController::class, 'updatePassword']);
    Route::put('/settings/notifications', [\App\Http\Controllers\SettingsController::class, 'updateNotifications']);
    Route::put('/settings/system',        [\App\Http\Controllers\SettingsController::class, 'updateSystem']);

    // Compliance
    Route::get('/compliance/stats', [\App\Http\Controllers\ComplianceController::class, 'stats']);
    Route::get('/compliance', [\App\Http\Controllers\ComplianceController::class, 'index']);
    Route::put('/compliance/{id}', [\App\Http\Controllers\ComplianceController::class, 'update']);
    Route::post('/compliance/{id}/remind', [\App\Http\Controllers\ComplianceController::class, 'remind']);

    // Reminders
    Route::get('/reminders', [\App\Http\Controllers\ReminderController::class, 'index']);
    Route::post('/reminders', [\App\Http\Controllers\ReminderController::class, 'store']);
    Route::put('/reminders/{id}', [\App\Http\Controllers\ReminderController::class, 'update']);
    Route::delete('/reminders/{id}', [\App\Http\Controllers\ReminderController::class, 'destroy']);

    // Feedback / CSAT
    Route::get('/feedback', [\App\Http\Controllers\FeedbackController::class, 'index']);
    Route::post('/feedback', [\App\Http\Controllers\FeedbackController::class, 'storeEntry']);
    Route::post('/feedback/request', [\App\Http\Controllers\FeedbackController::class, 'requestFeedback']);
    Route::get('/feedback/requests', [\App\Http\Controllers\FeedbackController::class, 'requests']);
    Route::post('/feedback/requests/{id}/rate', [\App\Http\Controllers\FeedbackController::class, 'rate']);

    // Performance
    Route::get('/performance', [\App\Http\Controllers\PerformanceController::class, 'index']);
    Route::post('/performance/reviews/{id}/submit', [\App\Http\Controllers\PerformanceController::class, 'submitReview']);
    Route::post('/performance/goals', [\App\Http\Controllers\PerformanceController::class, 'storeGoal']);
    Route::put('/performance/goals/{id}', [\App\Http\Controllers\PerformanceController::class, 'updateGoal']);
    Route::post('/performance/feedback360', [\App\Http\Controllers\PerformanceController::class, 'storeFeedback360']);
    Route::delete('/performance/goals/{id}', [\App\Http\Controllers\PerformanceController::class, 'deleteGoal']);

    // Recruitment
    Route::get('/recruitment', [\App\Http\Controllers\RecruitmentController::class, 'index']);
    Route::post('/recruitment/jobs', [\App\Http\Controllers\RecruitmentController::class, 'storeJob']);
    Route::put('/recruitment/jobs/{id}', [\App\Http\Controllers\RecruitmentController::class, 'updateJob']);
    Route::post('/recruitment/candidates', [\App\Http\Controllers\RecruitmentController::class, 'storeCandidate']);
    Route::put('/recruitment/candidates/{id}', [\App\Http\Controllers\RecruitmentController::class, 'updateCandidate']);

    // Offboarding
    Route::get('/offboarding', [\App\Http\Controllers\OffboardingController::class, 'index']);
    Route::post('/offboarding', [\App\Http\Controllers\OffboardingController::class, 'store']);
    Route::put('/offboarding/{id}/checklist', [\App\Http\Controllers\OffboardingController::class, 'updateChecklist']);

    // Leave Management (aliases for /hrms/leaves)
    Route::get('/leaves', [LeaveController::class, 'index']);
    Route::post('/leaves', [LeaveController::class, 'store']);
    Route::put('/leaves/{id}', [LeaveController::class, 'update']);

    // Integrations
    Route::get('/integrations', [\App\Http\Controllers\IntegrationController::class, 'index']);
    Route::post('/integrations/{slug}/toggle', [\App\Http\Controllers\IntegrationController::class, 'toggle']);
    Route::post('/integrations/{slug}/config', [\App\Http\Controllers\IntegrationController::class, 'saveConfig']);
    Route::post('/integrations/{slug}/test', [\App\Http\Controllers\IntegrationController::class, 'test']);
    Route::get('/integrations/{slug}/logs', [\App\Http\Controllers\WebhookController::class, 'logs']);

    // Client Portal
    Route::get('/portal/clients', [\App\Http\Controllers\PortalController::class, 'clients']);
    Route::post('/portal/clients/{id}/toggle', [\App\Http\Controllers\PortalController::class, 'toggle']);
    Route::post('/portal/invite-all', [\App\Http\Controllers\PortalController::class, 'inviteAll']);
    Route::post('/portal/create', [\App\Http\Controllers\PortalController::class, 'create']);
    Route::post('/portal/clients/{id}/reset-password', [\App\Http\Controllers\PortalController::class, 'resetPassword']);
    Route::post('/portal/bulk', [\App\Http\Controllers\PortalController::class, 'bulk']);
    Route::get('/portal/clients/{id}/users', [\App\Http\Controllers\PortalController::class, 'clientUsers']);
    Route::post('/portal/clients/{id}/users', [\App\Http\Controllers\PortalController::class, 'addClientUser']);
    Route::delete('/portal/clients/{id}/users/{userId}', [\App\Http\Controllers\PortalController::class, 'removeClientUser']);
    Route::get('/portal/recent-activity', [\App\Http\Controllers\PortalController::class, 'recentActivity']);
    Route::post('/portal/clients/{id}/users/{userId}/reset-password', [\App\Http\Controllers\PortalController::class, 'resetUserPassword']);

    // Custom project codes (patent office / service)
    Route::get('/project-codes', [\App\Http\Controllers\ProjectCodeController::class, 'index']);
    Route::post('/project-codes', [\App\Http\Controllers\ProjectCodeController::class, 'store']);

    // Bulk operations
    Route::post('/bulk/execute', [\App\Http\Controllers\BulkController::class, 'execute']);

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
