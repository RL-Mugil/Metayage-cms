<?php

namespace App\Services;

use App\Models\ComplianceItem;
use App\Models\User;
use App\Support\DataAccess;
use Illuminate\Database\Eloquent\Builder;

/** Compliance items a user may see (Compliance page, AI tools). */
class ComplianceScope
{
    public function query(User $user): Builder
    {
        $query = ComplianceItem::query();
        if ($user->isClientRole() || $user->isInventor()) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->isGalvanizer()) {
            $codes = $user->galvanizerCircleCodes();
            $query->where(function (Builder $scope) use ($user, $codes): void {
                $scope->where('assignee_id', $user->id)
                    ->orWhereHas('project', fn (Builder $projects) => $projects->whereIn('circle', $codes ?: ['__none__']));
            });
        } elseif (! DataAccess::seesAllCases($user)) {
            // Patent Analysts / Engineers / Attorneys: deadlines of their CM / SCM cases, plus items assigned to them.
            $query->where(function (Builder $scope) use ($user): void {
                $scope->where('assignee_id', $user->id)
                    ->orWhereHas('project', fn (Builder $projects) => DataAccess::scopeManagedCases($projects, $user));
            });
        }

        return $query;
    }
}
