<?php

namespace App\Support;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who sees which data — the one place for the firm's access model:
 *
 *   all cases  + all finance   System Admin, Director (partner/director), Accountant (finance), HR
 *   all cases, finance by list Paralegal — finance only when on Settings → Finance → Financial access
 *   own cases only             everyone else (Patent Analyst / Engineer = associate, Patent Attorney =
 *                              manager …): cases where they are Client Manager (CM) or Secondary CM
 *   circle                     Galvanizer (unchanged)
 *
 * Anyone on the Financial access list sees all finance whatever their role.
 */
class DataAccess
{
    public const ALL_CASE_ROLES = ['super_admin', 'partner', 'director', 'finance', 'hr', 'paralegal'];

    public const ALL_FINANCE_ROLES = ['super_admin', 'partner', 'director', 'finance', 'hr'];

    /** Finance actions (mark paid, waive, edit register rows) — on top of the Financial access list. */
    public const FINANCE_ACTION_ROLES = ['super_admin', 'partner', 'director', 'finance'];

    public const SETTING_KEY = 'finance_access_user_ids';

    /** @var array<int, int>|null */
    private static ?array $financeList = null;

    public static function seesAllCases(User $user): bool
    {
        return in_array($user->role, self::ALL_CASE_ROLES, true);
    }

    /** @return array<int, int> */
    public static function financeAccessList(): array
    {
        if (self::$financeList === null) {
            $raw = DB::table('system_settings')->where('key', self::SETTING_KEY)->value('value');
            self::$financeList = array_values(array_unique(array_map('intval', (array) (json_decode((string) $raw, true) ?: []))));
        }

        return self::$financeList;
    }

    public static function forgetFinanceList(): void
    {
        self::$financeList = null;
    }

    public static function onFinanceList(User $user): bool
    {
        return in_array((int) $user->id, self::financeAccessList(), true);
    }

    public static function seesAllFinance(User $user): bool
    {
        return in_array($user->role, self::ALL_FINANCE_ROLES, true) || self::onFinanceList($user);
    }

    /** May act on finance (mark paid, waive, edit or delete register rows). */
    public static function canActOnFinance(User $user): bool
    {
        return in_array($user->role, self::FINANCE_ACTION_ROLES, true) || self::onFinanceList($user);
    }

    /**
     * May open finance pages at all: all-finance users, galvanizers (their circle) and the
     * case-scoped staff (their CM / SCM cases). A paralegal off the list may not.
     */
    public static function canOpenFinance(User $user): bool
    {
        if ($user->isClientRole() || $user->isInventor()) {
            return false;
        }
        if (self::seesAllFinance($user) || $user->isGalvanizer()) {
            return true;
        }

        return ! self::seesAllCases($user);
    }

    /** Limit a projects query to the user's own CM / SCM cases. */
    public static function scopeManagedCases($query, User $user, string $table = 'projects'): void
    {
        $id = (int) $user->id;
        $query->where(fn ($q) => $q->where("{$table}.assigned_manager_id", $id)->orWhere("{$table}.secondary_manager_id", $id));
    }

    /**
     * Limit a Client query to the clients the user may see: everyone for the full-view roles,
     * the circle for a galvanizer, otherwise clients with at least one case where the user is
     * Client Manager (PCM) or Secondary Client Manager (SCM).
     */
    public static function scopeManagedClients($query, User $user): void
    {
        if (self::seesAllCases($user)) {
            return;
        }
        if ($user->isGalvanizer()) {
            $user->applyClientScope($query);

            return;
        }
        $query->whereHas('projects', fn ($p) => self::scopeManagedCases($p, $user));
    }

    /** Ids of the user's CM / SCM cases. @return array<int, int> */
    public static function managedCaseIds(User $user): array
    {
        $q = Project::query();
        self::scopeManagedCases($q, $user);

        return $q->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Limit any finance query (register rows, Zoho invoices, legacy invoices …) to what the
     * user may see. Clients keep their own client-based scoping in the callers.
     *
     * @param  string  $projectFk  the row's project column (qualified if needed)
     */
    public static function scopeFinance($query, User $user, string $projectFk = 'project_id'): void
    {
        if (self::seesAllFinance($user) || $user->isClientRole()) {
            return;
        }
        if ($user->isGalvanizer()) {
            $query->whereIn($projectFk, Project::query()->select('id')->where(fn ($q) => $user->applyProjectScope($q)));

            return;
        }
        if (! self::canOpenFinance($user)) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->whereIn($projectFk, Project::query()->select('id')->where(fn ($q) => self::scopeManagedCases($q, $user)));
    }

    /** A cache-key fragment for per-scope finance figures. */
    public static function financeScopeKey(User $user): string
    {
        if (self::seesAllFinance($user)) {
            return 'all';
        }
        if ($user->isClientRole()) {
            return 'client-'.$user->id;
        }

        return ($user->isGalvanizer() ? 'circle-' : 'cases-').$user->id;
    }

    /** @return array{all_cases: bool, all_finance: bool, finance: bool, finance_actions: bool} */
    public static function forFrontend(User $user): array
    {
        return [
            'all_cases' => self::seesAllCases($user),
            'all_finance' => self::seesAllFinance($user),
            'finance' => self::canOpenFinance($user),
            'finance_actions' => self::canActOnFinance($user),
        ];
    }
}
