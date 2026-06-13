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
        if (!preg_match('/^\s*SELECT\b/i', $sql)) {
            throw new \RuntimeException('Only SELECT queries are permitted.');
        }
        if (preg_match('/\b(DROP|DELETE|INSERT|UPDATE|ALTER|TRUNCATE|EXEC|GRANT|REVOKE)\b/i', $sql)) {
            throw new \RuntimeException('Unsafe SQL keyword detected.');
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
- users: id, name, email, role (super_admin|partner|manager|hr|finance|associate|client), status, created_at
- clients: id, client_code, company_name, industry, status, account_manager_id→users, date_onboarded, portal_enabled
- client_contacts: id, client_id→clients, name, email, phone, role_type
- projects: id, project_code, docket_number, title, case_type, status, start_date, due_date, client_id→clients, partner_id→users, manager_id→users, patent_engineer_id→users
- tasks: id, title, status, priority, due_date, project_id→projects, assignee_id→users
- invoices: id, invoice_code, invoice_type, client_id→clients, project_id→projects, status, subtotal, total_amount, balance_due, issue_date, due_date, currency
- employees: id, employee_code, user_id→users, full_name, work_email, phone, department_id→departments, designation_id→designations, employment_type, employment_status, work_location, date_of_joining
- departments: id, name
- designations: id, title
- attendances: id, employee_id→employees, attendance_date, check_in, check_out, status (Present|Absent|Half Day|On Leave), duration_minutes
- leave_requests: id, employee_id→employees, leave_type, from_date, to_date, total_days, status (Pending|Approved|Rejected|Cancelled), reason
- leave_balances: id, employee_id→employees, year, earned_leave, sick_leave, casual_leave, lop_days
- compliance_items: id, matter, type (Patent|Trademark|Copyright), jurisdiction (USPTO|EPO|WIPO|IPO India), deadline, action_required, assignee, status (Critical|At Risk|On Track|Compliant|Resolved)
- tracker_rows: id, docket_number, client_name, record_type, pcm_id→users, scm_id→users, pr_id→users, status, delivery_due_date, payment_status, percentage_of_completion

RESPONSE FORMAT:
- For general questions: answer directly and helpfully. No need for SQL.
- For data questions: give a brief plain-English answer, then optionally include a ```sql...``` block.
- Keep responses concise and useful.

Current user: {$name} (role: {$role})
PROMPT;
    }
}
