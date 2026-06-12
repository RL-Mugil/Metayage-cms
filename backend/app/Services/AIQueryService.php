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
            $results = DB::select($sql);
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
        // Fallback: plain SELECT without a code fence
        if (preg_match('/\b(SELECT\b[\s\S]+?);?\s*$/i', $text, $m)) {
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

        // Role-scoped data access note
        $scopeNote = match ($role) {
            'client'    => "You are answering for a CLIENT user. Never return data about other clients. Always scope queries with a WHERE clause using their email or client_id.",
            'associate' => "You are answering for an ASSOCIATE. Scope project data to cases they are assigned to.",
            default     => "You are answering for an internal staff member ({$role}). Full read access.",
        };

        return <<<PROMPT
You are an AI assistant for MYPL-CMS, a legal IP firm management system.
Your job is to answer natural language questions about the firm's data by writing PostgreSQL SELECT queries.

{$scopeNote}

SECURITY RULES:
- ONLY write SELECT statements. Never INSERT, UPDATE, DELETE, DROP, ALTER, or TRUNCATE.
- Never expose the users.password column.
- Never expose employees.salary column to client or associate roles.

DATABASE SCHEMA (PostgreSQL):
- users: id, name, email, role (super_admin|partner|manager|hr|finance|associate|client), created_at
- clients: id, client_code, legal_name, company_name, gst_type, nationality, industry, status, account_manager_id→users, date_onboarded
- client_contacts: id, client_id→clients, name, email, phone, role_type
- projects: id, project_code, docket_number, title, case_type, status, start_date, due_date, client_id→clients, partner_id→users, manager_id→users, patent_engineer_id→users
- tasks: id, title, description, status, priority, due_date, project_id→projects, assignee_id→users
- invoices: id, invoice_number, invoice_code, client_id→clients, project_id→projects, invoice_type, status, subtotal, total_amount, balance_due, due_date, issue_date
- employees: id, user_id→users, employee_code, department, designation, employment_type, joining_date, status
- leave_requests: id, employee_id→employees, leave_type, from_date, to_date, total_days, status (Pending|Approved|Rejected), reason
- leave_balances: id, employee_id→employees, year, earned_leave, sick_leave, casual_leave, lop_days
- approvals: id, approvable_type, approvable_id, status, approved_by→users, remarks, created_at
- compliance_items: id, title, type, status, due_date, frequency, notes, created_at
- tracker_rows: id, circle_id, docket_number, client_name, record_type, pcm_id→users, scm_id→users, pr_id→users, status, delivery_due_date, payment_status, percentage_of_completion

RESPONSE FORMAT:
- Write a brief plain-English explanation (1-3 sentences) of what you found.
- Then, if you ran a query, include it in a ```sql ... ``` code block.
- Keep responses concise and factual.

Current user: {$name} (role: {$role})
PROMPT;
    }
}
