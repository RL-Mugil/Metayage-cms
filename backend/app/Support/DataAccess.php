<?php

namespace App\Support;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
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
 *
 * Open access (Settings > System, System Admin): while a switch is on, the case-scoped staff
 * see, and may edit, every client / case / client portal, e.g. to claim their clients.
 * The switches never open finance and never widen Bulk Operations.
 */
class DataAccess
{
    public const ALL_CASE_ROLES = ['super_admin', 'partner', 'director', 'finance', 'hr', 'paralegal'];

    public const ALL_FINANCE_ROLES = ['super_admin', 'partner', 'director', 'finance', 'hr'];

    /** Finance actions (mark paid, waive, edit register rows) — on top of the Financial access list. */
    public const FINANCE_ACTION_ROLES = ['super_admin', 'partner', 'director', 'finance'];

    public const SETTING_KEY = 'finance_access_user_ids';

    public const OPEN_ACCESS_KEY = 'open_access';

    /** Shared cache (not a per-process static) so long-lived queue workers see a change at once. */
    private const CACHE_KEY = 'data_access_settings';

    /** @return array{finance: array<int, int>, open: array{clients: bool, cases: bool, portal: bool, updated_by: ?string, updated_at: ?string}} */
    private static function settings(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $rows = DB::table('system_settings')->whereIn('key', [self::SETTING_KEY, self::OPEN_ACCESS_KEY])->pluck('value', 'key');
            $finance = array_values(array_unique(array_map('intval', (array) (json_decode((string) ($rows[self::SETTING_KEY] ?? ''), true) ?: []))));
            $open = (array) (json_decode((string) ($rows[self::OPEN_ACCESS_KEY] ?? ''), true) ?: []);

            return [
                'finance' => $finance,
                'open' => [
                    'clients' => (bool) ($open['clients'] ?? false),
                    'cases' => (bool) ($open['cases'] ?? false),
                    'portal' => (bool) ($open['portal'] ?? false),
                    'updated_by' => $open['updated_by'] ?? null,
                    'updated_at' => $open['updated_at'] ?? null,
                ],
            ];
        });
    }

    /** Forget the cached Financial access list and Open access switches (after a change). */
    public static function forgetSettings(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function forgetFinanceList(): void
    {
        self::forgetSettings();
    }

    /** @return array{clients: bool, cases: bool, portal: bool, updated_by: ?string, updated_at: ?string} */
    public static function openAccess(): array
    {
        return self::settings()['open'];
    }

    /** Role alone (ignores the Open access switches): System Admin, Director, Accountant, HR, Paralegal. */
    public static function hasFullCaseRole(User $user): bool
    {
        return in_array($user->role, self::ALL_CASE_ROLES, true);
    }

    /** The case-scoped staff an Open access switch widens (Patent Analyst / Engineer, Patent Attorney). */
    private static function opened(User $user, string $area): bool
    {
        return in_array($user->role, User::CASE_SCOPED_ROLES, true) && self::openAccess()[$area];
    }

    public static function seesAllCases(User $user): bool
    {
        return self::hasFullCaseRole($user) || self::opened($user, 'cases');
    }

    public static function seesAllClients(User $user): bool
    {
        return self::hasFullCaseRole($user) || self::opened($user, 'clients');
    }

    public static function seesAllPortal(User $user): bool
    {
        return self::hasFullCaseRole($user) || self::opened($user, 'portal');
    }

    /** Client access widened by the Open access switch (not by role). */
    public static function clientsOpenedBySwitch(User $user): bool
    {
        return ! self::hasFullCaseRole($user) && self::opened($user, 'clients');
    }

    /** Case access widened by the Open access switch (not by role). */
    public static function casesOpenedBySwitch(User $user): bool
    {
        return ! self::hasFullCaseRole($user) && self::opened($user, 'cases');
    }

    /** @return array<int, int> */
    public static function financeAccessList(): array
    {
        return self::settings()['finance'];
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

        return ! self::hasFullCaseRole($user);
    }

    /** Limit a projects query to the user's own CM / SCM cases. */
    public static function scopeManagedCases($query, User $user, string $table = 'projects'): void
    {
        $id = (int) $user->id;
        $query->where(fn ($q) => $q->where("{$table}.assigned_manager_id", $id)->orWhere("{$table}.secondary_manager_id", $id));
    }

    /**
     * Limit a Client query to the user's own clients: the circle for a galvanizer, otherwise
     * clients where they are the Client Manager (clients.account_manager_id, set when they claim
     * a client) or PCM / SCM on at least one case. Callers decide whether to scope at all.
     */
    public static function scopeOwnClients($query, User $user): void
    {
        if ($user->isGalvanizer()) {
            $user->applyClientScope($query);

            return;
        }
        $id = (int) $user->id;
        $query->where(fn ($q) => $q->where('clients.account_manager_id', $id)
            ->orWhereHas('projects', fn ($p) => self::scopeManagedCases($p, $user)));
    }

    /** Clients page and client pickers: all for full-view roles (or the Clients switch), else own clients. */
    public static function scopeManagedClients($query, User $user): void
    {
        if ($user->isClientRole() || self::seesAllClients($user)) {
            return;
        }
        self::scopeOwnClients($query, $user);
    }

    /** Client Portal page: all for full-view roles (or the Client Portal switch), else own clients. */
    public static function scopePortalClients($query, User $user): void
    {
        if (self::seesAllPortal($user)) {
            return;
        }
        self::scopeOwnClients($query, $user);
    }

    /** Is this one of the user's own clients (staff; client logins use Client::isVisibleToUser). */
    public static function ownsClient(User $user, \App\Models\Client $client): bool
    {
        $q = \App\Models\Client::query()->whereKey($client->id);
        self::scopeOwnClients($q, $user);

        return $q->exists();
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

    /** @return array<string, bool> */
    public static function forFrontend(User $user): array
    {
        return [
            'all_cases' => self::seesAllCases($user),
            'all_clients' => self::seesAllClients($user),
            'all_finance' => self::seesAllFinance($user),
            'finance' => self::canOpenFinance($user),
            'finance_actions' => self::canActOnFinance($user),
        ];
    }
}
