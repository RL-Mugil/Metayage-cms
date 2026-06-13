<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
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

    // FK and enum annotations that information_schema doesn't carry.
    // Column *names* come from the DB; these lines enrich them with domain context.
    private const SCHEMA_ANNOTATIONS = [
        'users'           => 'role (super_admin|partner|manager|hr|finance|associate|paralegal|client), status (Active|Inactive)',
        'clients'         => 'client_type (individual|organization), gst_type (B2B|B2C|Export|Unregistered), status (Active|Inactive|Prospect|On Hold), account_manager_id→users',
        'projects'        => 'project_type (Patent|Trademark|Copyright|Design|Trade Secret), patent_office_code (IN|US|EP|WO|AU|CA), status (Open|In Progress|On Hold|Closed|Completed), urgency (Low|Medium|High|Critical), assigned_partner_id→users, assigned_manager_id→users, patent_engineer_id→users, client_id→clients',
        'project_stages'  => 'stage_name (Intake|Drafting|Filing|Examination|Object received|Granted|Renewal), status (Pending|In Progress|Completed), project_id→projects',
        'tasks'           => 'status (Pending|In Progress|Review|Completed|Blocked), priority (Low|Medium|High|Urgent), project_id→projects, assignee_id→users, reviewer_id→users',
        'time_entries'    => 'status (Draft|Approved|Invoiced), project_id→projects, task_id→tasks, user_id→users',
        'invoices'        => 'status (Draft|Sent|Viewed|Partially Paid|Paid|Overdue|Cancelled), client_id→clients, project_id→projects',
        'payments'        => 'status (Completed|Pending|Failed), client_id→clients, invoice_id→invoices',
        'client_ledgers'  => 'document_type (Invoice|Payment|Credit Note), client_id→clients',
        'employees'       => 'employment_type (Full-time|Part-time|Contract), employment_status (Active|Inactive|On Leave|Terminated), work_location (Office|Remote|Hybrid), user_id→users, department_id→departments, designation_id→designations',
        'attendances'     => 'status (Present|Absent|Half Day|On Leave), employee_id→employees',
        'leave_requests'  => 'leave_type (Earned Leave|Casual Leave|Sick Leave|LOP), status (Pending|Approved|Rejected|Cancelled), employee_id→employees',
        'leave_balances'  => 'employee_id→employees',
        'payroll_runs'    => 'status (Draft|Finalized|Paid), run_by_id→users',
        'payslips'        => 'payroll_run_id→payroll_runs, employee_id→employees',
        'compliance_items'=> 'type (Patent|Trademark|Copyright), status (Critical|At Risk|On Track|Compliant|Resolved)',
        'tracker_rows'    => 'payment_status (Paid|Partial|Pending), pcm_id→users, scm_id→users, pr_id→users',
    ];

    // Whitelisted tables the AI is allowed to query.
    private const ALLOWED_TABLES = [
        'users', 'clients', 'client_contacts', 'projects', 'project_stages',
        'tasks', 'time_entries', 'invoices', 'invoice_items', 'payments',
        'client_ledgers', 'employees', 'departments', 'designations',
        'attendances', 'leave_requests', 'leave_balances',
        'payroll_runs', 'payslips', 'compliance_items',
        'tracker_rows', 'public_holidays',
    ];

    private function schemaContext(): string
    {
        return Cache::remember('ai_schema_context', 3600, function () {
            try {
                $tables = self::ALLOWED_TABLES;
                $placeholders = implode(',', array_fill(0, count($tables), '?'));
                $rows = DB::select(
                    "SELECT table_name, column_name
                     FROM information_schema.columns
                     WHERE table_schema = 'public'
                       AND table_name IN ({$placeholders})
                     ORDER BY table_name, ordinal_position",
                    $tables
                );

                $grouped = [];
                foreach ($rows as $row) {
                    $grouped[$row->table_name][] = $row->column_name;
                }

                $lines = [];
                foreach (self::ALLOWED_TABLES as $table) {
                    if (!isset($grouped[$table])) continue;
                    $cols = implode(', ', $grouped[$table]);
                    $note = self::SCHEMA_ANNOTATIONS[$table] ?? '';
                    $lines[] = "- {$table}: {$cols}" . ($note ? " — [{$note}]" : '');
                }

                return implode("\n", $lines);
            } catch (\Exception) {
                // Fallback: return table names only so the prompt stays valid
                return implode("\n", array_map(fn($t) => "- {$t}", self::ALLOWED_TABLES));
            }
        });
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
{$this->schemaContext()}

RESPONSE FORMAT:
- For general questions: answer directly and helpfully. No need for SQL.
- For data questions: give a brief plain-English answer, then optionally include a ```sql...``` block.
- Keep responses concise and useful.

Current user: {$name} (role: {$role})
PROMPT;
    }
}
