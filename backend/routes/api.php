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
Route::post('/webhooks/zoho/deletions', [\App\Http\Controllers\ZohoDeletionWebhookController::class, 'receive'])->middleware('throttle:120,1');
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
    // Was never actually registered despite the frontend "Elevate" button/modal calling it —
    // a pre-existing 404. Fixed here since the new renewal-paid flow relies on the same
    // InventionFamilyService::createBranch() this wraps.
    Route::post('/projects/{id}/elevate', [ProjectController::class, 'elevate']);
    Route::post('/projects/{id}/require-document', [ProjectController::class, 'requireDocument']);
    Route::post('/projects/docket-import/preview', [\App\Http\Controllers\ProjectDocketImportController::class, 'preview'])->middleware('throttle:60,1');
    Route::post('/projects/docket-import/import', [\App\Http\Controllers\ProjectDocketImportController::class, 'import'])->middleware('throttle:30,1');

    // Canonical IP assets — durable legal records linked to one or more service engagements.
    Route::get('/ip-records', [\App\Http\Controllers\IpRecordController::class, 'index']);
    Route::post('/ip-records', [\App\Http\Controllers\IpRecordController::class, 'store']);
    Route::get('/ip-records/{ipRecord}', [\App\Http\Controllers\IpRecordController::class, 'show']);

    // Docketing engine — events, statutory deadlines, renewals
    Route::get('/docket/upcoming', [\App\Http\Controllers\DocketController::class, 'upcoming']);
    Route::get('/docket/worklist', \App\Http\Controllers\DocketWorklistController::class);
    Route::get('/projects/{id}/docket', [\App\Http\Controllers\DocketController::class, 'show']);
    Route::post('/projects/{id}/docket/events', [\App\Http\Controllers\DocketController::class, 'storeEvent']);
    Route::post('/projects/{id}/docket/renewal-paid', [\App\Http\Controllers\DocketController::class, 'storeRenewalPaid']);
    Route::put('/docket/events/{id}', [\App\Http\Controllers\DocketController::class, 'updateEvent']);
    Route::delete('/docket/events/{id}', [\App\Http\Controllers\DocketController::class, 'destroyEvent']);
    Route::post('/projects/{id}/docket/deadlines', [\App\Http\Controllers\DocketController::class, 'storeDeadline']);
    Route::patch('/docket/deadlines/{id}', [\App\Http\Controllers\DocketController::class, 'updateDeadline']);
    Route::delete('/docket/deadlines/{id}', [\App\Http\Controllers\DocketController::class, 'destroyDeadline']);
    Route::get('/docket/rules', [\App\Http\Controllers\DocketController::class, 'rules']);
    Route::patch('/docket/rules/{id}', [\App\Http\Controllers\DocketController::class, 'approveRule']);
    Route::post('/docket/rules/{id}/copy', [\App\Http\Controllers\DocketController::class, 'copyRule']);
    Route::post('/docket/rules/{id}/simulate', [\App\Http\Controllers\DocketController::class, 'simulateRule']);
    Route::patch('/projects/{id}/docket/application', [\App\Http\Controllers\DocketController::class, 'updateApplication']);
    Route::post('/projects/{id}/docket/renewals', [\App\Http\Controllers\DocketController::class, 'storeRenewal']);
    Route::patch('/docket/renewals/{id}', [\App\Http\Controllers\DocketController::class, 'updateRenewal']);
    Route::get('/reminder-profile', [\App\Http\Controllers\ReminderProfileController::class, 'show']);
    Route::put('/reminder-profile', [\App\Http\Controllers\ReminderProfileController::class, 'update']);
    Route::post('/reminder-profile/preview', [\App\Http\Controllers\ReminderProfileController::class, 'preview']);

    // Renewal approve -> invoice -> proof -> confirm loop (RenewalActionController, on PatentInvoiceIn)
    Route::get('/pending-payments', [\App\Http\Controllers\RenewalActionController::class, 'index']);
    Route::post('/projects/{id}/renewals/approve', [\App\Http\Controllers\RenewalActionController::class, 'approve']);
    Route::post('/pending-payments/{id}/approve-quote', [\App\Http\Controllers\RenewalActionController::class, 'approveQuote']);
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
    Route::put('/hrms/employees/{id}/portal-access', [HRMSController::class, 'updatePortalAccess']);
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
    Route::get('/financial/analytics', [FinancialController::class, 'analytics']);
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
    Route::put('/approvals/{id}', [\App\Http\Controllers\ApprovalController::class, 'update']);
    Route::post('/approvals/{id}/cancel', [\App\Http\Controllers\ApprovalController::class, 'cancel']);
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
    Route::get('/settings/codes',                 [\App\Http\Controllers\SettingsController::class, 'codesIndex']);
    Route::post('/settings/codes/rename-preview', [\App\Http\Controllers\SettingsController::class, 'previewCodeRename']);
    Route::put('/settings/codes/rename',          [\App\Http\Controllers\SettingsController::class, 'executeCodeRename']);
    Route::post('/settings/codes/merge-preview',  [\App\Http\Controllers\SettingsController::class, 'previewCodeMerge']);
    Route::put('/settings/codes/merge',           [\App\Http\Controllers\SettingsController::class, 'executeCodeMerge']);
    Route::put('/settings/project-tabs',  [\App\Http\Controllers\SettingsController::class, 'updateProjectTabs']);
    Route::put('/settings/renewal-fee-rates', [\App\Http\Controllers\SettingsController::class, 'updateRenewalFeeRates']);
    Route::put('/settings/payroll', [\App\Http\Controllers\SettingsController::class, 'updatePayrollSettings']);
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
    Route::post('/compliance', [\App\Http\Controllers\ComplianceController::class, 'store']);
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
    Route::post('/integrations/{slug}/test', [\App\Http\Controllers\IntegrationController::class, 'test'])->middleware('throttle:integration-test');
    Route::get('/integrations/{slug}/logs', [\App\Http\Controllers\WebhookController::class, 'logs']);
    Route::get('/integrations/zoho/all', [\App\Http\Controllers\ZohoBooksController::class, 'index']);
    Route::get('/integrations/zoho/opening-balances', [\App\Http\Controllers\ZohoBooksController::class, 'openingBalances']);
    Route::put('/integrations/zoho/{id}/link-project', [\App\Http\Controllers\ZohoBooksController::class, 'linkProject']);
    Route::get('/integrations/zoho/clients/{clientId}/summary', [\App\Http\Controllers\ZohoBooksController::class, 'clientSummary']);
    Route::get('/integrations/zoho/me/summary', [\App\Http\Controllers\ZohoBooksController::class, 'mySummary']);
    Route::get('/integrations/zoho/projects/{projectId}/summary', [\App\Http\Controllers\ZohoBooksController::class, 'projectSummary']);
    Route::post('/integrations/zoho/match', [\App\Http\Controllers\ZohoBooksController::class, 'matchBatch']);
    Route::post('/integrations/zoho/sync', [\App\Http\Controllers\ZohoBooksController::class, 'sync'])->middleware('throttle:zoho-full-sync');
    Route::post('/integrations/zoho/emergency-sync', [\App\Http\Controllers\ZohoBooksController::class, 'emergencySync'])->middleware('throttle:zoho-full-sync');
    Route::post('/integrations/zoho/addon-limit', [\App\Http\Controllers\ZohoBooksController::class, 'configureAddon'])->middleware('throttle:zoho-full-sync');
    Route::post('/integrations/zoho/addon-sync', [\App\Http\Controllers\ZohoBooksController::class, 'addonSync'])->middleware('throttle:zoho-full-sync');
    Route::post('/integrations/zoho/records/{record}/sync', [\App\Http\Controllers\ZohoSyncController::class, 'refresh'])->middleware('throttle:zoho-refresh');
    Route::get('/integrations/zoho/sync-status', [\App\Http\Controllers\ZohoSyncController::class, 'status'])->middleware('throttle:zoho-read');
    Route::get('/integrations/zoho/quotes/{kind}/{id}', [\App\Http\Controllers\ZohoQuoteController::class, 'preview'])->whereIn('kind', ['standard', 'in'])->whereNumber('id')->middleware('throttle:zoho-read');
      Route::post('/integrations/zoho/quotes/{kind}/{id}', [\App\Http\Controllers\ZohoQuoteController::class, 'send'])->whereIn('kind', ['standard', 'in'])->whereNumber('id')->middleware('throttle:zoho-write');
      Route::post('/integrations/zoho/quotes/{kind}/{id}/delete-in-zoho', [\App\Http\Controllers\ZohoQuoteController::class, 'deleteInZoho'])->whereIn('kind', ['standard', 'in'])->whereNumber('id')->middleware('throttle:zoho-write');
    Route::get('/integrations/zoho/records/{record}/pdf', [\App\Http\Controllers\ZohoSyncController::class, 'pdf'])->middleware('throttle:zoho-read');
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
    Route::post('/payroll/runs/{id}/submit-for-approval', [\App\Http\Controllers\PayrollController::class, 'submitForApproval']);
    Route::post('/payroll/runs/{id}/approve', [\App\Http\Controllers\PayrollController::class, 'approve']);
    Route::post('/payroll/runs/{id}/reject', [\App\Http\Controllers\PayrollController::class, 'reject']);
    Route::post('/payroll/runs/{id}/recall', [\App\Http\Controllers\PayrollController::class, 'recall']);
    Route::post('/payroll/runs/{id}/pay', [\App\Http\Controllers\PayrollController::class, 'markPaid']);
    Route::post('/payroll/runs/{id}/pay-selected', [\App\Http\Controllers\PayrollController::class, 'payslipsBulkPay']);
    Route::get('/payroll/runs/{id}/bank-advice', [\App\Http\Controllers\PayrollController::class, 'downloadBankAdvice']);
    Route::get('/payroll/runs/{id}/tds-worksheets', [\App\Http\Controllers\PayrollController::class, 'downloadTdsWorksheets']);
    Route::put('/payroll/payslips/{id}', [\App\Http\Controllers\PayrollController::class, 'updatePayslip']);
    Route::post('/payroll/payslips/{id}/withhold', [\App\Http\Controllers\PayrollController::class, 'withholdPayslip']);
    Route::post('/payroll/payslips/{id}/revert-withhold', [\App\Http\Controllers\PayrollController::class, 'revertWithhold']);
    Route::post('/payroll/employees/{employeeId}/release-withheld', [\App\Http\Controllers\PayrollController::class, 'releaseWithheld']);
    Route::get('/payroll/lop-adjustments', [\App\Http\Controllers\PayrollController::class, 'lopAdjustments']);
    Route::post('/payroll/lop-adjustments', [\App\Http\Controllers\PayrollController::class, 'createLopAdjustment']);
    Route::get('/payroll/my-slips', [\App\Http\Controllers\PayrollController::class, 'mySlips']);
    Route::get('/payroll/payslips/{id}/pdf', [\App\Http\Controllers\PayslipPdfController::class, 'monthly']);
    Route::get('/payroll/employees/{employeeId}/yearly-pdf', [\App\Http\Controllers\PayslipPdfController::class, 'yearly']);
    Route::get('/payroll/employees/{employeeId}/salary-certificate', [\App\Http\Controllers\PayslipPdfController::class, 'salaryCertificate']);
    Route::get('/payroll/employees/{employeeId}/payslip-summary', [\App\Http\Controllers\PayslipPdfController::class, 'summary']);
    Route::get('/payroll/employees/{employeeId}/payslips', [\App\Http\Controllers\PayrollController::class, 'employeeSlips']);

    // PAN/Aadhaar/EntityLocker verification (API Setu — see Settings -> Integrations)
    Route::post('/employees/{employeeId}/verify-pan', [\App\Http\Controllers\PanVerificationController::class, 'verify']);
    Route::post('/employees/{employeeId}/digilocker/consent', [\App\Http\Controllers\DigiLockerConsentController::class, 'start']);
    Route::post('/me/digilocker/consent', [\App\Http\Controllers\DigiLockerConsentController::class, 'startMine']);
    Route::get('/me/digilocker/status', [\App\Http\Controllers\DigiLockerConsentController::class, 'statusMine']);
    Route::post('/clients/{clientId}/verify-entity', [\App\Http\Controllers\EntityLockerController::class, 'verify']);

    // Payroll Portal (Zoho-style shell — aggregation views over the data above)
    Route::get('/payroll-portal/dashboard', [\App\Http\Controllers\PayrollPortalController::class, 'dashboard']);
    Route::get('/payroll-portal/approvals/reimbursements', [\App\Http\Controllers\PayrollPortalController::class, 'pendingReimbursements']);

    // Employee loans (repaid via payroll — see GeneratePayrollJob)
    Route::get('/loans', [\App\Http\Controllers\LoanController::class, 'index']);
    Route::post('/loans', [\App\Http\Controllers\LoanController::class, 'store']);
    Route::post('/loans/{id}/close', [\App\Http\Controllers\LoanController::class, 'close']);
    Route::get('/loans/mine', [\App\Http\Controllers\LoanController::class, 'mine']);
    Route::post('/loans/request', [\App\Http\Controllers\LoanController::class, 'request']);
    Route::post('/loans/{id}/approve-request', [\App\Http\Controllers\LoanController::class, 'approveRequest']);
    Route::post('/loans/{id}/reject-request', [\App\Http\Controllers\LoanController::class, 'rejectRequest']);

    // Contractors (invoice-driven, Section 194J/194C — separate from employee payroll)
    Route::get('/contractors', [\App\Http\Controllers\ContractorController::class, 'index']);
    Route::post('/contractors', [\App\Http\Controllers\ContractorController::class, 'store']);
    Route::put('/contractors/{id}', [\App\Http\Controllers\ContractorController::class, 'update']);
    Route::delete('/contractors/{id}', [\App\Http\Controllers\ContractorController::class, 'destroy']);
    Route::post('/contractors/{id}/payments', [\App\Http\Controllers\ContractorController::class, 'recordPayment']);

    // Investment declarations (employee self-service + HR review, resolved via /approvals/resolve type=Investment)
    Route::post('/investment-declarations', [\App\Http\Controllers\InvestmentDeclarationController::class, 'store']);
    Route::post('/investment-declarations/{id}/submit', [\App\Http\Controllers\InvestmentDeclarationController::class, 'submit']);
    Route::get('/investment-declarations/mine', [\App\Http\Controllers\InvestmentDeclarationController::class, 'mine']);
    Route::get('/investment-declarations/pending', [\App\Http\Controllers\InvestmentDeclarationController::class, 'pending']);
    Route::get('/investment-declarations/employee/{employeeId}', [\App\Http\Controllers\InvestmentDeclarationController::class, 'forEmployee']);
    Route::post('/investment-declarations/employee/{employeeId}', [\App\Http\Controllers\InvestmentDeclarationController::class, 'storeForEmployee']);
    Route::post('/investment-declarations/employee/{employeeId}/{id}/submit', [\App\Http\Controllers\InvestmentDeclarationController::class, 'submitForEmployee']);

    // Proof of Investments — separate, document-backed review phase that follows IT Declaration above
    Route::get('/poi-windows/{financialYear}', [\App\Http\Controllers\ProofOfInvestmentController::class, 'window']);
    Route::get('/poi-windows', [\App\Http\Controllers\ProofOfInvestmentController::class, 'windows']);
    Route::post('/poi-windows', [\App\Http\Controllers\ProofOfInvestmentController::class, 'storeWindow']);
    Route::get('/proof-of-investments/mine/{financialYear}', [\App\Http\Controllers\ProofOfInvestmentController::class, 'mine']);
    Route::post('/proof-of-investments/mine/{financialYear}/documents/{section}', [\App\Http\Controllers\ProofOfInvestmentController::class, 'uploadDocument']);
    Route::delete('/proof-of-investments/documents/{documentId}', [\App\Http\Controllers\ProofOfInvestmentController::class, 'deleteDocument']);
    Route::get('/proof-of-investments/documents/{documentId}/download', [\App\Http\Controllers\ProofOfInvestmentController::class, 'downloadDocument']);
    Route::get('/proof-of-investments/pending/{financialYear}', [\App\Http\Controllers\ProofOfInvestmentController::class, 'pending']);
    Route::get('/proof-of-investments/employee/{employeeId}', [\App\Http\Controllers\ProofOfInvestmentController::class, 'forEmployee']);
    Route::put('/proof-of-investments/{id}', [\App\Http\Controllers\ProofOfInvestmentController::class, 'setApprovedAmounts']);
    Route::post('/proof-of-investments/{id}/consider-for-it', [\App\Http\Controllers\ProofOfInvestmentController::class, 'considerForIt']);
    Route::post('/proof-of-investments/{financialYear}/approve-all-and-consider', [\App\Http\Controllers\ProofOfInvestmentController::class, 'approveAllAndConsider']);

    // Salary revisions (proposed by HR/manager, resolved via /approvals/resolve type=SalaryRevision)
    Route::get('/salary-revisions', [\App\Http\Controllers\SalaryRevisionController::class, 'index']);
    Route::post('/salary-revisions', [\App\Http\Controllers\SalaryRevisionController::class, 'store']);
    Route::get('/salary-revisions/pending', [\App\Http\Controllers\SalaryRevisionController::class, 'pending']);

    // Form 16 — Part B computation + TRACES Part A merge (see Form16Controller docblock)
    Route::get('/form16/{financialYear}', [\App\Http\Controllers\Form16Controller::class, 'index']);
    Route::post('/form16/{financialYear}/upload-part-a', [\App\Http\Controllers\Form16Controller::class, 'uploadPartAZip']);
    Route::post('/form16/{employeeId}/{financialYear}/generate', [\App\Http\Controllers\Form16Controller::class, 'generate']);
    Route::get('/form16/{employeeId}/{financialYear}/download', [\App\Http\Controllers\Form16Controller::class, 'download']);
    Route::get('/form16/{financialYear}/export-24q', [\App\Http\Controllers\Form16Controller::class, 'export24QData']);
    Route::post('/form16/{financialYear}/request', [\App\Http\Controllers\Form16Controller::class, 'request']);
    Route::post('/form16/{employeeId}/{financialYear}/publish', [\App\Http\Controllers\Form16Controller::class, 'publish']);
    Route::post('/form16/{financialYear}/publish-all', [\App\Http\Controllers\Form16Controller::class, 'publishAll']);

    // Taxes & Forms — TDS Liabilities (auto-generated on run approval), Challans, Form 24Q
    Route::get('/tds-liabilities', [\App\Http\Controllers\TdsLiabilityController::class, 'index']);
    Route::get('/tds-liabilities/{id}', [\App\Http\Controllers\TdsLiabilityController::class, 'show']);
    Route::get('/challans', [\App\Http\Controllers\ChallanController::class, 'index']);
    Route::post('/challans', [\App\Http\Controllers\ChallanController::class, 'store']);
    Route::post('/challans/{id}/associate', [\App\Http\Controllers\ChallanController::class, 'associate']);
    Route::delete('/challan-allocations/{id}', [\App\Http\Controllers\ChallanController::class, 'dissociate']);
    Route::get('/form-24q/{financialYear}/{quarter}/exceptions', [\App\Http\Controllers\Form24QController::class, 'exceptions']);
    Route::get('/form-24q/{financialYear}/{quarter}/export', [\App\Http\Controllers\Form24QController::class, 'export']);

    // Tax slab configs (Settings → Payroll → Taxes — admin-editable, see TaxSlabConfig)
    Route::get('/tax-slab-configs', [\App\Http\Controllers\TaxSlabConfigController::class, 'index']);
    Route::post('/tax-slab-configs', [\App\Http\Controllers\TaxSlabConfigController::class, 'store']);
    Route::put('/tax-slab-configs/{id}', [\App\Http\Controllers\TaxSlabConfigController::class, 'update']);

    // Salary component template (flat/percentage/remainder rows PayrollService applies to every employee)
    Route::get('/salary-components', [\App\Http\Controllers\SalaryComponentController::class, 'index']);
    Route::post('/salary-components', [\App\Http\Controllers\SalaryComponentController::class, 'store']);
    Route::put('/salary-components/{id}', [\App\Http\Controllers\SalaryComponentController::class, 'update']);
    Route::delete('/salary-components/{id}', [\App\Http\Controllers\SalaryComponentController::class, 'destroy']);
    Route::post('/salary-components/reorder', [\App\Http\Controllers\SalaryComponentController::class, 'reorder']);

    // Reimbursement types (admin-configured catalog) + employee claim submission
    Route::get('/reimbursement-types', [\App\Http\Controllers\ReimbursementTypeController::class, 'index']);
    Route::post('/reimbursement-types', [\App\Http\Controllers\ReimbursementTypeController::class, 'store']);
    Route::put('/reimbursement-types/{id}', [\App\Http\Controllers\ReimbursementTypeController::class, 'update']);
    Route::delete('/reimbursement-types/{id}', [\App\Http\Controllers\ReimbursementTypeController::class, 'destroy']);
    Route::post('/reimbursements', [\App\Http\Controllers\ReimbursementController::class, 'store']);
    Route::get('/reimbursements/mine', [\App\Http\Controllers\ReimbursementController::class, 'mine']);
    Route::get('/reimbursements/{id}/receipt', [\App\Http\Controllers\ReimbursementController::class, 'downloadReceipt']);
});
