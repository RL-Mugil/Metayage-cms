<?php

namespace App\Ai;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** The read-only tools shared by the AI Assistant page and the Claude MCP connector. */
class ToolRegistry
{
    /** @var array<int, class-string<Tool>> */
    private const TOOLS = [
        Tools\SearchClients::class,
        Tools\GetClient::class,
        Tools\SearchCases::class,
        Tools\GetCase::class,
        Tools\UpcomingDeadlines::class,
        Tools\ComplianceItems::class,
        Tools\ListTasks::class,
        Tools\CaseAnalytics::class,
        Tools\TeamWorkload::class,
        Tools\FinanceSummary::class,
        Tools\OutstandingInvoices::class,
        Tools\ListReports::class,
        Tools\GenerateReport::class,
        Tools\SignReport::class,
        Tools\ReportHistory::class,
        Tools\DownloadSavedReport::class,
        Tools\DeadlineCalendar::class,
        Tools\PendingPayments::class,
        Tools\Collections::class,
        Tools\ClientStatement::class,
        Tools\UnbilledAndQuoted::class,
        Tools\RevenueForecast::class,
        Tools\RevenueBreakdown::class,
        Tools\PeriodComparison::class,
        Tools\CaseTimeline::class,
        Tools\SearchDocuments::class,
        Tools\PortfolioSummary::class,
        Tools\DashboardKpis::class,
        Tools\MyDay::class,
        Tools\SearchTracker::class,
        Tools\StaffDirectory::class,
        Tools\CaseDiscussions::class,
        Tools\MyHr::class,
    ];

    /** @return array<string, Tool> */
    public function for(User $user): array
    {
        $tools = [];
        foreach (self::TOOLS as $class) {
            $tool = app($class);
            if ($tool->availableTo($user)) {
                $tools[$tool->name()] = $tool;
            }
        }

        return $tools;
    }

    /**
     * Run one tool as the user. Errors a user may see (not found, not allowed, bad input)
     * come back as ['error' => …]; anything else is rethrown.
     *
     * @return array<string, mixed>
     */
    public function call(User $user, string $name, array $args, string $source): array
    {
        $tool = $this->for($user)[$name] ?? null;
        if (! $tool) {
            return ['error' => "Unknown tool: {$name}"];
        }

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'ai_tool_call', 'subject_type' => 'AI',
            // A signature PIN is never written to the audit log.
            'metadata' => ['tool' => $name, 'arguments' => array_key_exists('pin', $args) ? ['pin' => '****'] + $args : $args, 'source' => $source],
            'ip_address' => request()?->ip(), 'user_agent' => mb_substr((string) request()?->userAgent(), 0, 255),
        ]);

        try {
            return $tool->handle($user, $args);
        } catch (ModelNotFoundException) {
            return ['error' => 'Not found, or not visible to you.'];
        } catch (AuthorizationException) {
            return ['error' => 'You do not have access to that record.'];
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['error' => implode(' ', $e->validator->errors()->all())];
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return ['error' => $e->getMessage() !== '' ? $e->getMessage() : 'Not allowed.'];
        }
    }
}
