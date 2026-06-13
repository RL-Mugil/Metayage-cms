<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class AIQueryService
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.groq.api_key');
        $this->model  = config('services.groq.model');
    }

    public function ask(string $userQuery, array $userContext): array
    {
        $systemPrompt = $this->buildSystemPrompt($userContext);

        $response = Http::withToken($this->apiKey)
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model'      => $this->model,
                'max_tokens' => 1024,
                'messages'   => [
                    ['role' => 'system',  'content' => $systemPrompt],
                    ['role' => 'user',    'content' => $userQuery],
                ],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Groq API error: ' . $response->body());
        }

        $content = $response->json('choices.0.message.content', '');

        // Extract SQL block if present
        $sql     = $this->extractSql($content);
        $results = [];
        $explain = $content;

        if ($sql) {
            $this->guardSql($sql);
            $results = DB::connection('ai_readonly')->select($sql);
            // Strip the raw SQL block from the explanation shown to the user
            $explain = trim(preg_replace('/```sql[\s\S]*?```/i', '', $content));
        }

        return [
            'response'  => $explain ?: 'Query executed successfully.',
            'sql_query' => $sql,
            'results'   => $results,
        ];
    }

    private function extractSql(string $text): ?string
    {
        if (preg_match('/```sql\s*([\s\S]*?)```/i', $text, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function guardSql(string $sql): void
    {
        // Allow SELECT or WITH ... SELECT (CTEs)
        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) {
            throw new \RuntimeException('Only SELECT queries are permitted.');
        }
        if (preg_match('/\b(DROP|DELETE|INSERT|UPDATE|ALTER|TRUNCATE|EXEC|EXECUTE|GRANT|REVOKE|COPY|VACUUM)\b/i', $sql)) {
            throw new \RuntimeException('Unsafe SQL keyword detected.');
        }
        // Block stacked statements
        if (substr_count($sql, ';') > 1) {
            throw new \RuntimeException('Multiple statements are not permitted.');
        }
    }

    private function buildSystemPrompt(array $ctx): string
    {
        $role = $ctx['role'] ?? 'staff';
        $name = $ctx['name'] ?? 'User';

        $scopeNote = match ($role) {
            'client'    => "The current user is a CLIENT. When querying firm data, always scope to their records only (never expose other clients' data).",
            'associate' => "The current user is an ASSOCIATE. Scope project queries to cases they are assigned to.",
            default     => "The current user is internal staff (role: {$role}) with full read access.",
        };

        return <<<PROMPT
You are a helpful AI assistant for MYPL-CMS, an IP law firm management system. You have two modes:

1. GENERAL ASSISTANT: Answer any question the user asks — legal concepts, IP law, general knowledge, how to use the system, advice, calculations, anything. You are like ChatGPT embedded in this product.

2. DATA QUERIES: When the user asks about firm data (clients, cases, invoices, employees, attendance, etc.), you MAY write a PostgreSQL SELECT query wrapped in a ```sql ... ``` code block to fetch it. Only do this when live data is genuinely needed.

{$scopeNote}

SECURITY RULES (only apply when writing SQL):
- ONLY write SELECT statements. Never INSERT, UPDATE, DELETE, DROP, ALTER, or TRUNCATE.
- Never expose users.password in queries.
- Never expose employees.salary to client or associate roles.

DATABASE SCHEMA (only relevant when querying data):
- users: id, name, email, role (super_admin|partner|manager|hr|finance|associate|paralegal|client), status (Active|Inactive), created_at
- clients: id, client_code, company_name, legal_name, client_type (individual|organization), nationality, gst_type (B2B|B2C|Export|Unregistered), has_gstin, gstin, pan_number, status (Active|Inactive|Prospect|On Hold), account_manager_id→users, portal_enabled, created_at
- client_contacts: id, client_id→clients, name, email, phone, role_type
- projects: id, project_code, docket_number, project_name, project_type (Patent|Trademark|Copyright|Design|Trade Secret), case_type, patent_office_code (IN|US|EP|WO|AU|CA), status (Open|In Progress|On Hold|Closed|Completed), urgency (Low|Medium|High|Critical), hard_deadline, filing_date, assigned_partner_id→users, assigned_manager_id→users, patent_engineer_id→users, client_id→clients, created_at
- project_stages: id, project_id→projects, stage_name (Intake|Drafting|Filing|Examination|Object received|Granted|Renewal), status (Pending|In Progress|Completed), sequence_order, due_date
- tasks: id, title, status (Pending|In Progress|Review|Completed|Blocked), priority (Low|Medium|High|Urgent), due_date, project_id→projects, assignee_id→users, reviewer_id→users, billable, estimated_hours, actual_hours
- time_entries: id, project_id→projects, task_id→tasks, user_id→users, duration_hours, entry_date, billable, status (Draft|Approved|Invoiced)
- invoices: id, invoice_code, client_id→clients, project_id→projects, status (Draft|Sent|Viewed|Partially Paid|Paid|Overdue|Cancelled), subtotal, tax_amount, total_amount, balance_due, issue_date, due_date, currency, payment_terms
- invoice_items: id, invoice_id→invoices, description, quantity, unit_rate, amount, tax_rate
- payments: id, client_id→clients, invoice_id→invoices, receipt_code, payment_date, amount, payment_method, status (Completed|Pending|Failed)
- client_ledgers: id, client_id→clients, transaction_date, document_type (Invoice|Payment|Credit Note), document_reference, debit, credit, balance
- employees: id, employee_code, user_id→users, full_name, work_email, phone, department_id→departments, designation_id→designations, employment_type (Full-time|Part-time|Contract), employment_status (Active|Inactive|On Leave|Terminated), work_location (Office|Remote|Hybrid), date_of_joining
- departments: id, name
- designations: id, title, grade_band
- attendances: id, employee_id→employees, attendance_date, check_in, check_out, status (Present|Absent|Half Day|On Leave), duration_minutes, capture_method
- leave_requests: id, employee_id→employees, leave_type (Earned Leave|Casual Leave|Sick Leave|LOP), from_date, to_date, total_days, status (Pending|Approved|Rejected|Cancelled), reason, comments
- leave_balances: id, employee_id→employees, year, earned_leave, sick_leave, casual_leave, lop_days
- payroll_runs: id, month, year, status (Draft|Finalized|Paid), run_by_id→users
- payslips: id, payroll_run_id→payroll_runs, employee_id→employees, basic, hra, special_allowance, gross_pay, pf_employee, esi_employee, professional_tax, tds, total_deductions, net_pay, lop_days
- compliance_items: id, matter, type (Patent|Trademark|Copyright), jurisdiction (USPTO|EPO|WIPO|IPO India), deadline, action_required, assignee, status (Critical|At Risk|On Track|Compliant|Resolved), priority
- tracker_rows: id, docket_number, client_name, record_type, pcm_id→users, scm_id→users, pr_id→users, status, delivery_due_date, payment_status (Paid|Partial|Pending), percentage_of_completion
- public_holidays: id, date, name, country (IN)

RESPONSE FORMAT:
- For general questions: answer directly and helpfully. No need for SQL.
- For data questions: give a brief plain-English answer, then optionally include a ```sql...``` block.
- Keep responses concise and useful.

Current user: {$name} (role: {$role})
PROMPT;
    }
}
