<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class AIQueryService
{
    private string $apiKey;
    private string $model;

    private const FINANCIAL_TABLES = ['invoices', 'invoice_items', 'payments', 'client_ledgers', 'quotations'];
    private const HRMS_SENSITIVE_TABLES = ['payroll_runs', 'payslips'];

    private const SCHEMA_ANNOTATIONS = [
        'users' => 'role (super_admin|partner|manager|hr|finance|associate|paralegal|client), status (Active|Inactive)',
        'clients' => 'client_type (individual|organization), gst_type (B2B|B2C|Export|Unregistered), status (Active|Inactive|Prospect|On Hold), account_manager_id->users',
        'projects' => 'project_type (Patent|Trademark|Copyright|Design|Trade Secret), patent_office_code (IN|US|EP|WO|AU|CA), status (Open|In Progress|On Hold|Closed|Completed), urgency (Low|Medium|High|Critical), assigned_partner_id->users, assigned_manager_id->users, patent_engineer_id->users, client_id->clients',
        'project_stages' => 'stage_name (Intake|Drafting|Filing|Examination|Object received|Granted|Renewal), status (Pending|In Progress|Completed), project_id->projects',
        'tasks' => 'status (Pending|In Progress|Review|Completed|Blocked), priority (Low|Medium|High|Urgent), project_id->projects, assignee_id->users, reviewer_id->users',
        'time_entries' => 'status (Draft|Approved|Invoiced), project_id->projects, task_id->tasks, user_id->users',
        'invoices' => 'status (Draft|Sent|Viewed|Partially Paid|Paid|Overdue|Cancelled), client_id->clients, project_id->projects',
        'payments' => 'status (Completed|Pending|Failed), client_id->clients, invoice_id->invoices',
        'client_ledgers' => 'document_type (Invoice|Payment|Credit Note), client_id->clients',
        'employees' => 'employment_type (Full-time|Part-time|Contract), employment_status (Active|Inactive|On Leave|Terminated), work_location (Office|Remote|Hybrid), user_id->users, department_id->departments, designation_id->designations',
        'attendances' => 'status (Present|Absent|Half Day|On Leave), employee_id->employees',
        'leave_requests' => 'leave_type (Earned Leave|Casual Leave|Sick Leave|LOP), status (Pending|Approved|Rejected|Cancelled), employee_id->employees',
        'leave_balances' => 'employee_id->employees',
        'payroll_runs' => 'status (Draft|Finalized|Paid), run_by_id->users',
        'payslips' => 'payroll_run_id->payroll_runs, employee_id->employees',
        'compliance_items' => 'type (Patent|Trademark|Copyright), status (Critical|At Risk|On Track|Compliant|Resolved)',
        'tracker_rows' => 'payment_status (Paid|Partial|Pending), pcm_id->users, scm_id->users, pr_id->users',
    ];

    private const ALLOWED_TABLES = [
        'users', 'clients', 'client_contacts', 'projects', 'project_stages',
        'tasks', 'time_entries', 'invoices', 'invoice_items', 'payments',
        'client_ledgers', 'employees', 'departments', 'designations',
        'attendances', 'leave_requests', 'leave_balances',
        'payroll_runs', 'payslips', 'compliance_items',
        'tracker_rows', 'public_holidays',
    ];

    public function __construct()
    {
        $this->apiKey = (string) config('services.groq.api_key', '');
        $this->model = (string) config('services.groq.model', 'meta-llama/llama-4-scout-17b-16e-instruct');
    }

    public function ask(string $userQuery, array $userContext): array
    {
        if ($builtin = $this->handleBuiltinPrompt($userQuery, $userContext)) {
            return $builtin;
        }

        if (blank($this->apiKey)) {
            throw new \RuntimeException('AI is not configured yet. Set GROQ_API_KEY to enable free-form AI replies.');
        }

        $response = Http::withToken($this->apiKey)
            ->timeout(30)
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => $this->model,
                'max_tokens' => 1024,
                'messages' => [
                    ['role' => 'system', 'content' => $this->buildSystemPrompt($userContext)],
                    ['role' => 'user', 'content' => $userQuery],
                ],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Groq API error: ' . $response->body());
        }

        $content = (string) $response->json('choices.0.message.content', '');
        $sql = $this->extractSql($content);
        $results = [];
        $explanation = $content;

        if ($sql) {
            $this->guardSql($sql, $userContext);
            $results = DB::connection('ai_readonly')->select($sql);
            $explanation = trim((string) preg_replace('/```sql[\s\S]*?```/i', '', $content));
        }

        return [
            'response' => $explanation !== '' ? $explanation : 'Query executed successfully.',
            'sql_query' => $sql,
            'results' => $results,
        ];
    }

    private function extractSql(string $text): ?string
    {
        if (preg_match('/```sql\s*([\s\S]*?)```/i', $text, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function guardSql(string $sql, array $userContext = []): void
    {
        $role = $userContext['role'] ?? 'staff';

        if (! preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) {
            throw new \RuntimeException('Only SELECT queries are permitted.');
        }

        if (preg_match('/\b(DROP|DELETE|INSERT|UPDATE|ALTER|TRUNCATE|EXEC|EXECUTE|GRANT|REVOKE|COPY|VACUUM)\b/i', $sql)) {
            throw new \RuntimeException('Unsafe SQL keyword detected.');
        }

        if (substr_count($sql, ';') > 1) {
            throw new \RuntimeException('Multiple statements are not permitted.');
        }

        if (preg_match('/\bpassword\b/i', $sql)) {
            throw new \RuntimeException('Access to password column is not permitted.');
        }

        if (in_array($role, User::CLIENT_ROLES, true)) {
            throw new \RuntimeException('Live data queries are not available for client accounts.');
        }

        if (in_array($role, ['associate', 'paralegal', 'manager'], true)) {
            foreach (self::FINANCIAL_TABLES as $table) {
                if (preg_match('/\b' . preg_quote($table, '/') . '\b/i', $sql)) {
                    throw new \RuntimeException("Access to financial data ({$table}) is not permitted for your role.");
                }
            }

            foreach (self::HRMS_SENSITIVE_TABLES as $table) {
                if (preg_match('/\b' . preg_quote($table, '/') . '\b/i', $sql)) {
                    throw new \RuntimeException("Access to payroll data ({$table}) is not permitted for your role.");
                }
            }

            $piiColumns = ['salary', 'aadhaar_ssn_encrypted', 'pan_tax_id', 'bank_account_number', 'bank_ifsc_code', 'personal_email'];
            foreach ($piiColumns as $column) {
                if (preg_match('/\b' . preg_quote($column, '/') . '\b/i', $sql)) {
                    throw new \RuntimeException("Access to sensitive column ({$column}) is not permitted for your role.");
                }
            }
        }

        if ($role === 'hr') {
            foreach (self::FINANCIAL_TABLES as $table) {
                if (preg_match('/\b' . preg_quote($table, '/') . '\b/i', $sql)) {
                    throw new \RuntimeException("Access to financial data ({$table}) is not permitted for HR role.");
                }
            }
        }
    }

    private function schemaContext(): string
    {
        return Cache::remember('ai_schema_context', 3600, function () {
            try {
                $placeholders = implode(',', array_fill(0, count(self::ALLOWED_TABLES), '?'));
                $rows = DB::select(
                    "SELECT table_name, column_name
                     FROM information_schema.columns
                     WHERE table_schema = 'public'
                       AND table_name IN ({$placeholders})
                     ORDER BY table_name, ordinal_position",
                    self::ALLOWED_TABLES
                );

                $grouped = [];
                foreach ($rows as $row) {
                    $grouped[$row->table_name][] = $row->column_name;
                }

                $lines = [];
                foreach (self::ALLOWED_TABLES as $table) {
                    if (! isset($grouped[$table])) {
                        continue;
                    }

                    $columns = implode(', ', $grouped[$table]);
                    $note = self::SCHEMA_ANNOTATIONS[$table] ?? '';
                    $lines[] = "- {$table}: {$columns}" . ($note !== '' ? " - [{$note}]" : '');
                }

                return implode("\n", $lines);
            } catch (\Exception) {
                return implode("\n", array_map(fn ($table) => "- {$table}", self::ALLOWED_TABLES));
            }
        });
    }

    private function buildSystemPrompt(array $context): string
    {
        $role = $context['role'] ?? 'staff';
        $name = $context['name'] ?? 'User';

        $scopeNote = match ($role) {
            'client', 'client_admin' => "The current user is a client. Do not write SQL queries. Answer only with general knowledge and product guidance.",
            'associate' => "The current user is an associate. Scope data questions to matters they are assigned to when you generate SQL.",
            default => "The current user is internal staff (role: {$role}) with read access according to product rules.",
        };

        return <<<PROMPT
You are a helpful AI assistant for MYPL-CMS, an IP law firm management system.

You support two modes:
1. General assistant: answer product, IP law, workflow, drafting, or general questions directly.
2. Data queries: when live MYPL data is needed, you may include a PostgreSQL SELECT query inside a ```sql``` block.

{$scopeNote}

Security rules when writing SQL:
- Only SELECT statements.
- Never expose users.password.
- Never expose employees.salary to unauthorized roles.

Database schema:
{$this->schemaContext()}

Response format:
- General questions: answer directly, no SQL required.
- Data questions: brief explanation first, then optional SQL block.
- Keep answers concise and useful.

Current user: {$name} (role: {$role})
PROMPT;
    }

    private function handleBuiltinPrompt(string $userQuery, array $userContext): ?array
    {
        $normalized = mb_strtolower(trim($userQuery));

        return match ($normalized) {
            'show overdue matters' => $this->runBuiltinQuery(
                <<<'SQL'
SELECT
    p.id,
    p.project_code,
    p.docket_number,
    p.project_name,
    p.status,
    p.urgency,
    p.hard_deadline,
    c.company_name AS client_name
FROM projects p
LEFT JOIN clients c ON c.id = p.client_id
WHERE p.hard_deadline IS NOT NULL
  AND p.hard_deadline < CURRENT_DATE
  AND p.status NOT IN ('Granted', 'Completed', 'Closed')
ORDER BY p.hard_deadline ASC
LIMIT 25
SQL,
                'Overdue matters are listed below, ordered by the earliest missed deadline.',
                $userContext
            ),
            'summarize client portfolio' => $this->runBuiltinQuery(
                <<<'SQL'
SELECT
    c.id,
    c.client_code,
    COALESCE(c.company_name, c.legal_name) AS client_name,
    c.status,
    c.gst_type,
    COUNT(DISTINCT p.id) AS project_count,
    COUNT(DISTINCT CASE WHEN p.status NOT IN ('Completed', 'Closed', 'Granted') THEN p.id END) AS active_project_count
FROM clients c
LEFT JOIN projects p ON p.client_id = c.id
GROUP BY c.id, c.client_code, c.company_name, c.legal_name, c.status, c.gst_type
ORDER BY active_project_count DESC, project_count DESC, client_name ASC
LIMIT 25
SQL,
                'Here is a client portfolio summary ranked by active matters.',
                $userContext
            ),
            'list high priority tasks' => $this->runBuiltinQuery(
                <<<'SQL'
SELECT
    t.id,
    t.title,
    t.priority,
    t.status,
    t.due_date,
    u.name AS assignee_name,
    p.project_code,
    p.docket_number
FROM tasks t
LEFT JOIN users u ON u.id = t.assignee_id
LEFT JOIN projects p ON p.id = t.project_id
WHERE t.priority IN ('High', 'Urgent', 'Critical')
  AND t.status <> 'Completed'
ORDER BY
  CASE t.priority
    WHEN 'Critical' THEN 1
    WHEN 'Urgent' THEN 2
    WHEN 'High' THEN 3
    ELSE 4
  END,
  t.due_date ASC NULLS LAST
LIMIT 25
SQL,
                'These are the open high-priority tasks, ordered by urgency and due date.',
                $userContext
            ),
            'revenue this month' => $this->runBuiltinQuery(
                <<<'SQL'
SELECT
    COALESCE(SUM(amount), 0) AS revenue_received_this_month,
    COUNT(*) AS payment_count
FROM payments
WHERE status = 'Completed'
  AND DATE_TRUNC('month', created_at) = DATE_TRUNC('month', CURRENT_DATE)
SQL,
                'This month\'s recorded revenue is shown below from completed payments.',
                $userContext
            ),
            'upcoming ip deadlines' => $this->runBuiltinQuery(
                <<<'SQL'
SELECT
    p.id,
    p.project_code,
    p.docket_number,
    p.project_name,
    p.patent_office_code,
    p.status,
    p.hard_deadline,
    c.company_name AS client_name
FROM projects p
LEFT JOIN clients c ON c.id = p.client_id
WHERE p.hard_deadline IS NOT NULL
  AND p.hard_deadline >= CURRENT_DATE
ORDER BY p.hard_deadline ASC
LIMIT 25
SQL,
                'These are the nearest upcoming IP deadlines across active matters.',
                $userContext
            ),
            'team workload summary' => $this->runBuiltinQuery(
                <<<'SQL'
SELECT
    u.id AS user_id,
    u.name,
    COUNT(DISTINCT p.id) AS assigned_projects,
    COUNT(DISTINCT t.id) AS open_tasks
FROM users u
LEFT JOIN projects p
  ON p.patent_engineer_id = u.id
  AND p.status NOT IN ('Completed', 'Closed', 'Granted')
LEFT JOIN tasks t
  ON t.assignee_id = u.id
  AND t.status <> 'Completed'
WHERE u.role IN ('partner', 'manager', 'associate', 'paralegal')
GROUP BY u.id, u.name
HAVING COUNT(DISTINCT p.id) > 0 OR COUNT(DISTINCT t.id) > 0
ORDER BY (COUNT(DISTINCT p.id) + COUNT(DISTINCT t.id)) DESC, u.name ASC
LIMIT 25
SQL,
                'This is the current workload summary across internal matter-handling staff.',
                $userContext
            ),
            'clients with active patents' => $this->runBuiltinQuery(
                <<<'SQL'
SELECT
    c.id,
    c.client_code,
    COALESCE(c.company_name, c.legal_name) AS client_name,
    COUNT(*) AS active_patent_matters
FROM clients c
JOIN projects p ON p.client_id = c.id
WHERE p.project_type ILIKE '%Patent%'
  AND p.status NOT IN ('Granted', 'Completed', 'Closed')
GROUP BY c.id, c.client_code, c.company_name, c.legal_name
ORDER BY active_patent_matters DESC, client_name ASC
LIMIT 25
SQL,
                'These clients currently have active patent matters.',
                $userContext
            ),
            'projects ending this quarter' => $this->runBuiltinQuery(
                <<<'SQL'
SELECT
    p.id,
    p.project_code,
    p.docket_number,
    p.project_name,
    p.status,
    p.hard_deadline,
    c.company_name AS client_name
FROM projects p
LEFT JOIN clients c ON c.id = p.client_id
WHERE p.hard_deadline >= DATE_TRUNC('quarter', CURRENT_DATE)
  AND p.hard_deadline < DATE_TRUNC('quarter', CURRENT_DATE) + INTERVAL '3 months'
ORDER BY p.hard_deadline ASC
LIMIT 25
SQL,
                'These projects have deadlines in the current quarter.',
                $userContext
            ),
            default => null,
        };
    }

    private function runBuiltinQuery(string $sql, string $response, array $userContext): array
    {
        $this->guardSql($sql, $userContext);

        return [
            'response' => $response,
            'sql_query' => $sql,
            'results' => DB::connection('ai_readonly')->select($sql),
        ];
    }
}
