<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\InventionFamilyController;
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
use App\Http\Controllers\GoogleCalendarController;
use App\Http\Controllers\MobileDeviceController;
use App\Http\Controllers\MobileAuthController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\PatentInvoiceController;

// Login happens through the web route (session-based, see routes/web.php).
// The old public POST /api/login pointed at the same session login and
// always 500'd outside a browser — removed.

// Public webhook receiver — no auth, for external integrations
Route::post('/webhooks/{slug}', [\App\Http\Controllers\WebhookController::class, 'receive'])->middleware('throttle:120,1');

// Authenticated routes protected by Sanctum
Route::prefix('mobile')->middleware('throttle:20,1')->group(function () {
    Route::post('/auth/login', [MobileAuthController::class, 'login']);
    Route::post('/auth/logout', [MobileAuthController::class, 'logout'])->middleware('auth:sanctum');

    Route::middleware(['auth:sanctum', 'firm.context'])->group(function () {
        Route::get('/me', [MobileAuthController::class, 'me']);
        Route::post('/push-tokens', [MobileDeviceController::class, 'register']);
        Route::delete('/push-tokens', [MobileDeviceController::class, 'unregister']);
    });
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware(['auth:sanctum', 'throttle:120,1']);

Route::middleware(['auth:sanctum', 'firm.context', 'throttle:120,1'])->group(function () {
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
    Route::post('/clients/import', [ClientController::class, 'import'])->middleware('throttle:1000,1');
    Route::get('/clients', [ClientController::class, 'index']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::get('/clients/{id}', [ClientController::class, 'show']);
    Route::put('/clients/{id}', [ClientController::class, 'update']);
    Route::delete('/clients/{id}', [ClientController::class, 'destroy']);
    Route::get('/clients/{id}/detail', [ClientController::class, 'detail']);
    Route::post('/clients/{id}/contacts', [ClientController::class, 'addContact']);

    // Cases / Projects
    Route::get('/projects/stats', [ProjectController::class, 'stats']);
    Route::get('/projects/lifecycle-stats', [ProjectController::class, 'lifecycleStats']);
    Route::get('/projects/lifecycle-service-stats', [ProjectController::class, 'lifecycleServiceStats']);
    Route::get('/projects/import-template', [\App\Http\Controllers\ProjectImportController::class, 'template']);
    Route::post('/projects/import/sheets', [\App\Http\Controllers\ProjectImportController::class, 'inspectSheets'])->middleware('throttle:60,1');
    Route::post('/projects/import', [\App\Http\Controllers\ProjectImportController::class, 'import'])->middleware('throttle:60,1');
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{id}', [ProjectController::class, 'show']);
    Route::get('/projects/{id}/workspace', [ProjectController::class, 'workspace']);
    Route::get('/invention-families/{family}', [InventionFamilyController::class, 'show']);
    Route::post('/invention-families/{family}/engagements', [InventionFamilyController::class, 'storeBranch']);
    Route::put('/projects/{id}', [ProjectController::class, 'update']);
    Route::delete('/projects/{id}', [ProjectController::class, 'destroy']);
    Route::post('/projects/{id}/stage', [ProjectController::class, 'updateStage']);
    Route::get('/projects/{id}/detail', [ProjectController::class, 'detail']);
    Route::post('/projects/docket-import/preview', [\App\Http\Controllers\ProjectDocketImportController::class, 'preview'])->middleware('throttle:60,1');
    Route::post('/projects/docket-import/import', [\App\Http\Controllers\ProjectDocketImportController::class, 'import'])->middleware('throttle:30,1');

    // Docketing engine — events, statutory deadlines, renewals
    Route::get('/docket/upcoming', [\App\Http\Controllers\DocketController::class, 'upcoming']);
    Route::get('/projects/{id}/docket', [\App\Http\Controllers\DocketController::class, 'show']);
    Route::post('/projects/{id}/docket/events', [\App\Http\Controllers\DocketController::class, 'storeEvent']);
    Route::patch('/docket/deadlines/{id}', [\App\Http\Controllers\DocketController::class, 'updateDeadline']);
    Route::patch('/docket/deadlines/{id}/review', [\App\Http\Controllers\DocketController::class, 'reviewDeadline']);
    Route::get('/docket/rules', [\App\Http\Controllers\DocketController::class, 'rules']);
    Route::patch('/docket/rules/{id}', [\App\Http\Controllers\DocketController::class, 'approveRule']);
    Route::patch('/projects/{id}/docket/application', [\App\Http\Controllers\DocketController::class, 'updateApplication']);
    Route::post('/projects/{id}/docket/renewals', [\App\Http\Controllers\DocketController::class, 'storeRenewal']);
    Route::patch('/docket/renewals/{id}', [\App\Http\Controllers\DocketController::class, 'updateRenewal']);

    // Renewal approve -> invoice -> proof -> confirm loop (RenewalActionController, on PatentInvoiceIn)
    Route::get('/pending-payments', [\App\Http\Controllers\RenewalActionController::class, 'index']);
    Route::post('/projects/{id}/renewals/approve', [\App\Http\Controllers\RenewalActionController::class, 'approve']);
    Route::post('/pending-payments/{id}/proof', [\App\Http\Controllers\RenewalActionController::class, 'submitProof']);
    Route::post('/pending-payments/{id}/confirm', [\App\Http\Controllers\RenewalActionController::class, 'confirmReceipt']);
    Route::post('/pending-payments/{id}/status-note', [\App\Http\Controllers\RenewalActionController::class, 'postStatusNote']);

    // Inventor role (Phase 3) — staff manage a case's inventors here.
    Route::get('/projects/{id}/inventors', [\App\Http\Controllers\InventorController::class, 'index']);
    Route::post('/projects/{id}/inventors', [\App\Http\Controllers\InventorController::class, 'store']);
    Route::delete('/projects/{id}/inventors/{userId}', [\App\Http\Controllers\InventorController::class, 'destroy']);

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
    Route::post('/financial/invoices/batch', [FinancialController::class, 'batchUpdate']);
    Route::get('/financial/invoices/{id}', [FinancialController::class, 'showInvoice']);
    Route::put('/financial/invoices/{id}', [FinancialController::class, 'updateInvoice']);
    Route::delete('/financial/invoices/{id}', [FinancialController::class, 'deleteInvoice']);
    Route::get('/financial/quotations', [FinancialController::class, 'quotations']);
    Route::post('/financial/quotations', [FinancialController::class, 'storeQuotation']);
    Route::post('/financial/quotations/{id}/convert', [FinancialController::class, 'convertToInvoice']);
    Route::put('/financial/quotations/{id}', [FinancialController::class, 'updateQuotation']);
    Route::delete('/financial/quotations/{id}', [FinancialController::class, 'deleteQuotation']);
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
    Route::get('/documents/view', [DocumentController::class, 'view']);

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

    // Unread chat badge (case chats the user is assigned to + their DMs)
    Route::get('/chat/unread-count',        [\App\Http\Controllers\ThreadChatController::class, 'unreadCount']);

    // Direct messages + real-time thread chat (global Discussions)
    Route::get('/dm',                       [\App\Http\Controllers\ThreadChatController::class, 'dmIndex']);
    Route::get('/dm/contacts',              [\App\Http\Controllers\ThreadChatController::class, 'contacts']);
    Route::post('/dm/open/{userId}',        [\App\Http\Controllers\ThreadChatController::class, 'openDm']);
    Route::get('/threads/{threadId}/chat',          [\App\Http\Controllers\ThreadChatController::class, 'show']);
    Route::get('/threads/{threadId}/chat/history',  [\App\Http\Controllers\ThreadChatController::class, 'history']);
    Route::post('/threads/{threadId}/chat', [\App\Http\Controllers\ThreadChatController::class, 'send'])->middleware('throttle:60,1');
    Route::put('/threads/{threadId}/chat/messages/{messageId}',    [\App\Http\Controllers\ThreadChatController::class, 'update']);
    Route::delete('/threads/{threadId}/chat/messages/{messageId}', [\App\Http\Controllers\ThreadChatController::class, 'destroy']);
    Route::post('/threads/{threadId}/chat/read',       [\App\Http\Controllers\ThreadChatController::class, 'markRead']);
    Route::get('/threads/{threadId}/chat/attachment',  [\App\Http\Controllers\ThreadChatController::class, 'downloadAttachment']);

    // Per-case real-time chat (Google-Chat-style room, restricted to case people)
    Route::get('/projects/{projectId}/chat',                      [\App\Http\Controllers\ProjectChatController::class, 'show']);
    Route::get('/projects/{projectId}/chat/history',              [\App\Http\Controllers\ProjectChatController::class, 'history']);
    Route::post('/projects/{projectId}/chat',                     [\App\Http\Controllers\ProjectChatController::class, 'send'])->middleware('throttle:60,1');
    Route::put('/projects/{projectId}/chat/messages/{messageId}', [\App\Http\Controllers\ProjectChatController::class, 'update']);
    Route::delete('/projects/{projectId}/chat/messages/{messageId}', [\App\Http\Controllers\ProjectChatController::class, 'destroy']);
    Route::post('/projects/{projectId}/chat/read',                [\App\Http\Controllers\ProjectChatController::class, 'markRead']);
    Route::get('/projects/{projectId}/chat/attachment',           [\App\Http\Controllers\ProjectChatController::class, 'downloadAttachment']);

    // Settings
    Route::get('/settings',               [\App\Http\Controllers\SettingsController::class, 'getSettings']);
    Route::put('/settings/profile',       [\App\Http\Controllers\SettingsController::class, 'updateProfile']);
    Route::put('/settings/password',      [\App\Http\Controllers\SettingsController::class, 'updatePassword']);
    Route::put('/settings/notifications', [\App\Http\Controllers\SettingsController::class, 'updateNotifications']);
    Route::put('/settings/system',         [\App\Http\Controllers\SettingsController::class, 'updateSystem']);
    Route::put('/settings/feature-flags', [\App\Http\Controllers\SettingsController::class, 'updateFeatureFlags']);
    Route::put('/settings/dropdown',      [\App\Http\Controllers\SettingsController::class, 'updateDropdown']);
    Route::put('/settings/renewal-fee-rates', [\App\Http\Controllers\SettingsController::class, 'updateRenewalFeeRates']);
    Route::put('/settings/escalation-cadence', [\App\Http\Controllers\SettingsController::class, 'updateEscalationCadence']);
    Route::apiResource('fee-rate-cards', \App\Http\Controllers\FeeRateCardController::class)->except(['show']);
    Route::post('/settings/avatar',       [\App\Http\Controllers\SettingsController::class, 'uploadAvatar']);
    Route::delete('/settings/avatar',     [\App\Http\Controllers\SettingsController::class, 'removeAvatar']);

    // Google Calendar
    Route::get('/integrations/google-calendar/status',     [GoogleCalendarController::class, 'status']);
    Route::post('/integrations/google-calendar/disconnect',[GoogleCalendarController::class, 'disconnect']);

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
    Route::post('/reminders/{id}/help-request', [\App\Http\Controllers\ReminderController::class, 'helpRequest']);

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
    Route::get('/integrations/zoho/all', [\App\Http\Controllers\ZohoBooksController::class, 'index']);
    Route::get('/integrations/zoho/clients/{clientId}/summary', [\App\Http\Controllers\ZohoBooksController::class, 'clientSummary']);
    Route::get('/integrations/zoho/me/summary', [\App\Http\Controllers\ZohoBooksController::class, 'mySummary']);
    Route::get('/integrations/zoho/projects/{projectId}/summary', [\App\Http\Controllers\ZohoBooksController::class, 'projectSummary']);
    Route::post('/integrations/zoho/match', [\App\Http\Controllers\ZohoBooksController::class, 'matchBatch']);
    Route::post('/integrations/zoho/sync', [\App\Http\Controllers\ZohoBooksController::class, 'sync']);
    Route::get('/integrations/zoho/analytics/monthly', [\App\Http\Controllers\ZohoBooksController::class, 'monthlyAnalytics']);

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
    Route::get('/portal/my-team', [\App\Http\Controllers\PortalController::class, 'myTeam']);

    // Audit Log (super_admin / partner only — enforced in controller)
    Route::get('/audit-logs', [AuditLogController::class, 'index']);

    // Recycle Bin
    Route::get('/recycle-bin', [\App\Http\Controllers\RecycleBinController::class, 'index']);
    Route::post('/recycle-bin/restore', [\App\Http\Controllers\RecycleBinController::class, 'restore']);
    Route::delete('/recycle-bin/hard-delete', [\App\Http\Controllers\RecycleBinController::class, 'hardDelete']);
    Route::post('/recycle-bin/bulk-restore', [\App\Http\Controllers\RecycleBinController::class, 'bulkRestore']);
    Route::delete('/recycle-bin/bulk-hard-delete', [\App\Http\Controllers\RecycleBinController::class, 'bulkHardDelete']);

    // Patent Invoices & Quotations — Indian clients (INR)
    Route::get('/patent-invoices/in',                [PatentInvoiceController::class, 'index']);
    Route::post('/patent-invoices/in',               [PatentInvoiceController::class, 'store']);
    Route::post('/patent-invoices/in/batch',         [PatentInvoiceController::class, 'batch']);
    Route::post('/patent-invoices/in/{id}/convert',  [PatentInvoiceController::class, 'convert']);
    Route::put('/patent-invoices/in/{id}',           [PatentInvoiceController::class, 'update']);
    Route::delete('/patent-invoices/in/{id}',        [PatentInvoiceController::class, 'destroy']);

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
