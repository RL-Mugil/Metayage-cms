<?php

namespace App\Ai;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * A read-only question the AI Assistant and the Claude MCP connector may ask the portal.
 * Tools never write: they only run the same scoped queries the portal pages use, as the
 * signed-in user, and return whitelisted fields (no bank details, PAN, salaries, passwords).
 */
abstract class Tool
{
    public const MAX_ROWS = 50;

    /** Statuses of finished cases (not counted as open). */
    public const CLOSED_STATUSES = ['Completed', 'Archived', 'Closed', 'Granted', 'Refused', 'Abandoned', 'Withdrawn', 'Lapsed', 'Expired'];

    abstract public function name(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /** JSON Schema of the arguments. @return array<string, mixed> */
    abstract public function inputSchema(): array;

    /** @return array<string, mixed> */
    abstract public function handle(User $user, array $args): array;

    /** Whether the user may use this tool at all (e.g. finance tools). */
    public function availableTo(User $user): bool
    {
        return true;
    }

    /** Cases the user may see, exactly as the Cases page scopes them. */
    protected function cases(User $user): Builder
    {
        $query = Project::query();
        if ($user->isGalvanizer()) {
            $user->applyProjectScope($query);
        } elseif ($user->seesOnlyOwnCases()) {
            $query->assignedTo($user->id);
        } elseif ($user->isClientRole() || $user->isInventor()) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    protected function limit(array $args, int $default = 20): int
    {
        return max(1, min(self::MAX_ROWS, (int) ($args['limit'] ?? $default)));
    }

    protected function str(array $args, string $key): ?string
    {
        $v = trim((string) ($args[$key] ?? ''));

        return $v === '' ? null : mb_substr($v, 0, 200);
    }

    protected function like(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }

    protected static function date($value): ?string
    {
        if (! $value) {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }

    /** @return array<string, mixed> */
    protected function limitProp(): array
    {
        return ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_ROWS, 'description' => 'Maximum rows to return (default 20, max 50).'];
    }
}
