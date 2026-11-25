<?php

namespace App\Ai;

use App\Models\User;
use App\Support\DataAccess;

/** Ready-made prompts shown in Claude's prompt menu for the portal connector (MCP prompts). */
class Prompts
{
    /** @return array<string, array{title: string, description: string, finance: bool, arguments: array<int, array{name: string, description: string, required: bool}>, text: string}> */
    private function all(): array
    {
        return [
            'weekly_deadline_briefing' => [
                'title' => 'Weekly deadline briefing', 'finance' => false, 'arguments' => [],
                'description' => 'Deadlines, overdue cases and compliance items for the next 7 days, grouped by urgency.',
                'text' => 'Give me my weekly deadline briefing from the MyIPStrategy portal. Use upcoming_deadlines (7 days, include overdue), compliance_items (7 days) and my_day. '
                    .'Group by: overdue, due in 3 days, later this week. Show docket number, what is due and the date in a table, then list the 3 most urgent actions.',
            ],
            'client_review_pack' => [
                'title' => 'Client review pack', 'finance' => false,
                'arguments' => [['name' => 'client_code', 'description' => 'Client code, e.g. 382M', 'required' => true]],
                'description' => 'Everything about one client: cases, stages, deadlines and (with finance access) the statement of account.',
                'text' => 'Prepare a client review pack for client {client_code} from the MyIPStrategy portal. Use get_client, then get_case for the open cases, upcoming_deadlines, '
                    .'and if available client_statement. Summarise: portfolio, status of each open case, next deadlines, money owed and anything that needs attention.',
            ],
            'collections_follow_up' => [
                'title' => 'Collections follow-up list', 'finance' => true, 'arguments' => [],
                'description' => 'Overdue invoices to chase, oldest first, with contacts and the last follow-up note.',
                'text' => 'Build my collections follow-up list from the MyIPStrategy portal. Use pending_payments with overdue_only. Sort by days overdue, show client, contact email, '
                    .'invoice, balance, days overdue and the last follow-up note, and suggest a short follow-up line for the top 10. Then offer a Payment Collection report as Excel.',
            ],
            'month_end_finance_pack' => [
                'title' => 'Month-end finance pack', 'finance' => true,
                'arguments' => [['name' => 'month', 'description' => 'Month as YYYY-MM (default: last month)', 'required' => false]],
                'description' => 'Billed, collected, outstanding, ageing, comparison with the previous month, and the Financial Summary report file.',
                'text' => 'Prepare the month-end finance pack for {month} from the MyIPStrategy portal. Use finance_summary, collections for that month (group by client), '
                    .'period_comparison (month) and revenue_breakdown. Write a short commentary, then generate the financial-summary report as PDF for that month and give me the link.',
            ],
            'my_day' => [
                'title' => 'My day', 'finance' => false, 'arguments' => [],
                'description' => 'Your tasks, deadlines on your cases and compliance items for today.',
                'text' => 'Use my_day from the MyIPStrategy portal and give me a short plan for today: what is overdue, what is due today, and what to do first.',
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function list(User $user): array
    {
        $finance = DataAccess::canOpenFinance($user);

        return collect($this->all())->filter(fn ($p) => ! $p['finance'] || $finance)
            ->map(fn ($p, $name) => ['name' => $name, 'title' => $p['title'], 'description' => $p['description'], 'arguments' => $p['arguments']])
            ->values()->all();
    }

    /** @return array{description: string, messages: array<int, array<string, mixed>>}|null */
    public function get(User $user, string $name, array $args): ?array
    {
        $p = $this->all()[$name] ?? null;
        if (! $p || ($p['finance'] && ! DataAccess::canOpenFinance($user))) {
            return null;
        }
        $values = [
            'client_code' => mb_substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($args['client_code'] ?? '')), 0, 20) ?: '(ask me which client)',
            'month' => preg_match('/^\d{4}-\d{2}$/', (string) ($args['month'] ?? '')) ? $args['month'] : now()->subMonth()->format('Y-m'),
        ];
        $text = preg_replace_callback('/\{(\w+)\}/', fn ($m) => $values[$m[1]] ?? $m[0], $p['text']);

        return ['description' => $p['description'], 'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]]]];
    }
}
