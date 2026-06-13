<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Uses CREATE INDEX CONCURRENTLY via raw SQL to avoid write-locking tables in production.
// Laravel's Schema::table index methods lock the table briefly; CONCURRENTLY does not.
return new class extends Migration
{
    private function createConcurrently(string $table, string $indexName, array $columns): void
    {
        $cols = implode(', ', $columns);
        // CONCURRENTLY cannot run inside a transaction; each call is its own implicit txn.
        DB::unprepared("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$indexName} ON {$table} ({$cols})");
    }

    public function up(): void
    {
        // ── Clients ──────────────────────────────────────────────────────────
        $this->createConcurrently('clients', 'idx_clients_status',             ['status']);
        $this->createConcurrently('clients', 'idx_clients_gst_type',           ['gst_type']);
        $this->createConcurrently('clients', 'idx_clients_account_manager_id', ['account_manager_id']);
        $this->createConcurrently('client_contacts', 'idx_client_contacts_client_id', ['client_id']);
        $this->createConcurrently('client_contacts', 'idx_client_contacts_email',     ['email']);

        // ── Projects ─────────────────────────────────────────────────────────
        $this->createConcurrently('projects', 'idx_projects_client_id',            ['client_id']);
        $this->createConcurrently('projects', 'idx_projects_status',               ['status']);
        $this->createConcurrently('projects', 'idx_projects_assigned_partner_id',  ['assigned_partner_id']);
        $this->createConcurrently('projects', 'idx_projects_assigned_manager_id',  ['assigned_manager_id']);
        $this->createConcurrently('projects', 'idx_projects_patent_engineer_id',   ['patent_engineer_id']);
        $this->createConcurrently('projects', 'idx_projects_hard_deadline',        ['hard_deadline']);
        $this->createConcurrently('projects', 'idx_projects_project_type',         ['project_type']);
        $this->createConcurrently('projects', 'idx_projects_status_deadline',      ['status', 'hard_deadline']);
        $this->createConcurrently('projects', 'idx_projects_manager_status',       ['assigned_manager_id', 'status']);

        $this->createConcurrently('project_stages', 'idx_project_stages_project_id',     ['project_id']);
        $this->createConcurrently('project_stages', 'idx_project_stages_status',         ['status']);
        $this->createConcurrently('project_stages', 'idx_project_stages_project_seq',    ['project_id', 'sequence_order']);

        // ── Tasks & Time ─────────────────────────────────────────────────────
        $this->createConcurrently('tasks', 'idx_tasks_project_id',  ['project_id']);
        $this->createConcurrently('tasks', 'idx_tasks_assignee_id', ['assignee_id']);
        $this->createConcurrently('tasks', 'idx_tasks_status',      ['status']);
        $this->createConcurrently('tasks', 'idx_tasks_due_date',    ['due_date']);

        $this->createConcurrently('time_entries', 'idx_time_entries_user_id',        ['user_id']);
        $this->createConcurrently('time_entries', 'idx_time_entries_project_id',     ['project_id']);
        $this->createConcurrently('time_entries', 'idx_time_entries_entry_date',     ['entry_date']);
        $this->createConcurrently('time_entries', 'idx_time_entries_status_billable',['status', 'billable']);

        // ── Financial ────────────────────────────────────────────────────────
        $this->createConcurrently('invoices', 'idx_invoices_client_id',       ['client_id']);
        $this->createConcurrently('invoices', 'idx_invoices_project_id',      ['project_id']);
        $this->createConcurrently('invoices', 'idx_invoices_status',          ['status']);
        $this->createConcurrently('invoices', 'idx_invoices_issue_date',      ['issue_date']);
        $this->createConcurrently('invoices', 'idx_invoices_due_date',        ['due_date']);
        $this->createConcurrently('invoices', 'idx_invoices_client_issue',    ['client_id', 'issue_date']);
        $this->createConcurrently('invoices', 'idx_invoices_status_due',      ['status', 'due_date']);

        $this->createConcurrently('invoice_items', 'idx_invoice_items_invoice_id', ['invoice_id']);

        $this->createConcurrently('payments', 'idx_payments_client_id',  ['client_id']);
        $this->createConcurrently('payments', 'idx_payments_invoice_id', ['invoice_id']);

        $this->createConcurrently('client_ledgers', 'idx_client_ledgers_client_id',       ['client_id']);
        $this->createConcurrently('client_ledgers', 'idx_client_ledgers_transaction_date', ['transaction_date']);

        $this->createConcurrently('quotations', 'idx_quotations_client_id',  ['client_id']);
        $this->createConcurrently('quotations', 'idx_quotations_project_id', ['project_id']);
        $this->createConcurrently('quotations', 'idx_quotations_status',     ['status']);

        // ── HRMS ─────────────────────────────────────────────────────────────
        $this->createConcurrently('employees', 'idx_employees_user_id',           ['user_id']);
        $this->createConcurrently('employees', 'idx_employees_department_id',     ['department_id']);
        $this->createConcurrently('employees', 'idx_employees_designation_id',    ['designation_id']);
        $this->createConcurrently('employees', 'idx_employees_employment_status', ['employment_status']);

        $this->createConcurrently('attendances', 'idx_attendances_employee_id',      ['employee_id']);
        $this->createConcurrently('attendances', 'idx_attendances_attendance_date',  ['attendance_date']);
        $this->createConcurrently('attendances', 'idx_attendances_emp_date',         ['employee_id', 'attendance_date']);

        $this->createConcurrently('leave_requests', 'idx_leave_requests_employee_id', ['employee_id']);
        $this->createConcurrently('leave_requests', 'idx_leave_requests_status',      ['status']);

        $this->createConcurrently('leave_balances', 'idx_leave_balances_emp_year', ['employee_id', 'year']);

        $this->createConcurrently('payslips', 'idx_payslips_employee_id',     ['employee_id']);
        $this->createConcurrently('payslips', 'idx_payslips_payroll_run_id',  ['payroll_run_id']);

        // ── Audit & Collaboration ─────────────────────────────────────────────
        $this->createConcurrently('audit_logs', 'idx_audit_logs_user_id',      ['user_id']);
        $this->createConcurrently('audit_logs', 'idx_audit_logs_subject',      ['subject_type', 'subject_id']);
        $this->createConcurrently('audit_logs', 'idx_audit_logs_created_at',   ['created_at']);

        $this->createConcurrently('approvals', 'idx_approvals_approver_id',   ['approver_id']);
        $this->createConcurrently('approvals', 'idx_approvals_requester_id',  ['requester_id']);
        $this->createConcurrently('approvals', 'idx_approvals_status',        ['status']);
        $this->createConcurrently('approvals', 'idx_approvals_subject',       ['subject_type', 'subject_id']);

        $this->createConcurrently('ip_notifications', 'idx_notifications_user_read', ['user_id', 'is_read']);

        $this->createConcurrently('discussion_threads',  'idx_discussion_threads_project_id',   ['project_id']);
        $this->createConcurrently('discussion_messages', 'idx_discussion_messages_thread_id',   ['thread_id']);

        // ── Tracker ───────────────────────────────────────────────────────────
        $this->createConcurrently('tracker_rows', 'idx_tracker_rows_circle_id',          ['circle_id']);
        $this->createConcurrently('tracker_rows', 'idx_tracker_rows_delivery_due_date',  ['delivery_due_date']);
        $this->createConcurrently('tracker_rows', 'idx_tracker_rows_status',             ['status']);
        $this->createConcurrently('tracker_rows', 'idx_tracker_rows_payment_status',     ['payment_status']);
        $this->createConcurrently('tracker_rows', 'idx_tracker_rows_pcm_id',             ['pcm_id']);
        $this->createConcurrently('tracker_rows', 'idx_tracker_rows_scm_id',             ['scm_id']);
        $this->createConcurrently('tracker_rows', 'idx_tracker_rows_pr_id',              ['pr_id']);
        $this->createConcurrently('tracker_rows', 'idx_tracker_rows_project_id',         ['project_id']);
        $this->createConcurrently('tracker_rows', 'idx_tracker_rows_pcm_due',            ['pcm_id', 'delivery_due_date']);
        $this->createConcurrently('tracker_rows', 'idx_tracker_rows_scm_due',            ['scm_id', 'delivery_due_date']);
        $this->createConcurrently('tracker_rows', 'idx_tracker_rows_pr_due',             ['pr_id', 'delivery_due_date']);
        $this->createConcurrently('tracker_rows', 'idx_tracker_rows_circle_sort',        ['circle_id', 'sort_order', 'created_at']);

        // ── Module Tables ──────────────────────────────────────────────────────
        $this->createConcurrently('compliance_items', 'idx_compliance_status',   ['status']);
        $this->createConcurrently('compliance_items', 'idx_compliance_deadline', ['deadline']);

        $this->createConcurrently('reminders', 'idx_reminders_user_id',   ['user_id']);
        $this->createConcurrently('reminders', 'idx_reminders_due_date',  ['due_date']);
        $this->createConcurrently('reminders', 'idx_reminders_completed', ['completed']);
    }

    public function down(): void
    {
        $indexes = [
            'idx_clients_status', 'idx_clients_gst_type', 'idx_clients_account_manager_id',
            'idx_client_contacts_client_id', 'idx_client_contacts_email',
            'idx_projects_client_id', 'idx_projects_status', 'idx_projects_assigned_partner_id',
            'idx_projects_assigned_manager_id', 'idx_projects_patent_engineer_id',
            'idx_projects_hard_deadline', 'idx_projects_project_type',
            'idx_projects_status_deadline', 'idx_projects_manager_status',
            'idx_project_stages_project_id', 'idx_project_stages_status', 'idx_project_stages_project_seq',
            'idx_tasks_project_id', 'idx_tasks_assignee_id', 'idx_tasks_status', 'idx_tasks_due_date',
            'idx_time_entries_user_id', 'idx_time_entries_project_id',
            'idx_time_entries_entry_date', 'idx_time_entries_status_billable',
            'idx_invoices_client_id', 'idx_invoices_project_id', 'idx_invoices_status',
            'idx_invoices_issue_date', 'idx_invoices_due_date',
            'idx_invoices_client_issue', 'idx_invoices_status_due',
            'idx_invoice_items_invoice_id',
            'idx_payments_client_id', 'idx_payments_invoice_id',
            'idx_client_ledgers_client_id', 'idx_client_ledgers_transaction_date',
            'idx_quotations_client_id', 'idx_quotations_project_id', 'idx_quotations_status',
            'idx_employees_user_id', 'idx_employees_department_id',
            'idx_employees_designation_id', 'idx_employees_employment_status',
            'idx_attendances_employee_id', 'idx_attendances_attendance_date', 'idx_attendances_emp_date',
            'idx_leave_requests_employee_id', 'idx_leave_requests_status',
            'idx_leave_balances_emp_year',
            'idx_payslips_employee_id', 'idx_payslips_payroll_run_id',
            'idx_audit_logs_user_id', 'idx_audit_logs_subject', 'idx_audit_logs_created_at',
            'idx_approvals_approver_id', 'idx_approvals_requester_id',
            'idx_approvals_status', 'idx_approvals_subject',
            'idx_notifications_user_read',
            'idx_discussion_threads_project_id', 'idx_discussion_messages_thread_id',
            'idx_tracker_rows_circle_id', 'idx_tracker_rows_delivery_due_date',
            'idx_tracker_rows_status', 'idx_tracker_rows_payment_status',
            'idx_tracker_rows_pcm_id', 'idx_tracker_rows_scm_id', 'idx_tracker_rows_pr_id',
            'idx_tracker_rows_project_id', 'idx_tracker_rows_pcm_due',
            'idx_tracker_rows_scm_due', 'idx_tracker_rows_pr_due', 'idx_tracker_rows_circle_sort',
            'idx_compliance_status', 'idx_compliance_deadline',
            'idx_reminders_user_id', 'idx_reminders_due_date', 'idx_reminders_completed',
        ];

        foreach ($indexes as $idx) {
            DB::unprepared("DROP INDEX CONCURRENTLY IF EXISTS {$idx}");
        }
    }
};
