<?php

namespace App\Services;

use App\Models\DocketDeadline;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class DocketWorklistService
{
    public function query(User $user, array $filters = []): Builder
    {
        $query = DocketDeadline::with(['project.client:id,client_code,company_name,legal_name', 'ipRecord:id,record_code,record_type,title,responsible_user_id,backup_user_id'])
            ->where('status', 'Open')->where('supersession_status', 'Current');

        if (in_array($user->role, ['associate', 'paralegal'], true)) {
            $query->where(function ($scope) use ($user): void {
                $scope->whereHas('project', fn ($project) => $project
                    ->where('patent_engineer_id', $user->id)->orWhere('assigned_manager_id', $user->id)
                    ->orWhere('secondary_manager_id', $user->id)
                    ->orWhereHas('tasks', fn ($task) => $task->where('assignee_id', $user->id)))
                    ->orWhereHas('ipRecord', fn ($record) => $record->where('responsible_user_id', $user->id)->orWhere('backup_user_id', $user->id));
            });
        } elseif ($user->isGalvanizer()) {
            $query->whereHas('project', fn ($project) => $project->whereIn('circle', $user->galvanizerCircleCodes()));
        } elseif ($user->isInventor()) {
            $query->whereHas('project.inventors', fn ($inventor) => $inventor->whereKey($user->id));
        }

        if (! empty($filters['horizon_days'])) $query->whereDate('due_date', '<=', now()->addDays(min((int) $filters['horizon_days'], 365)));
        if (! empty($filters['from'])) $query->whereDate('due_date', '>=', $filters['from']);
        if (! empty($filters['to'])) $query->whereDate('due_date', '<=', $filters['to']);
        if (! empty($filters['risk_level'])) $query->where('risk_level', $filters['risk_level']);
        if (! empty($filters['review_status'])) $query->where('review_status', $filters['review_status']);
        if (! empty($filters['record_type'])) $query->whereHas('ipRecord', fn ($q) => $q->where('record_type', $filters['record_type']));
        if (! empty($filters['responsible_user_id'])) {
            $query->whereHas('ipRecord', fn ($q) => $q->where('responsible_user_id', $filters['responsible_user_id'])->orWhere('backup_user_id', $filters['responsible_user_id']));
        }

        return $query->orderBy('due_date')->orderBy('id');
    }

    public function serialize(DocketDeadline $deadline): array
    {
        $days = Carbon::today()->diffInDays($deadline->due_date, false);
        return [
            'id' => $deadline->id, 'title' => $deadline->title, 'legal_basis' => $deadline->legal_basis,
            'statutory_due_date' => ($deadline->statutory_due_date ?? $deadline->due_date)?->toDateString(),
            'operational_due_date' => $deadline->due_date?->toDateString(), 'days_remaining' => $days,
            'band' => $days < 0 ? 'overdue' : ($days <= 7 ? 'red' : ($days <= 30 ? 'amber' : 'green')),
            'risk_level' => $deadline->risk_level, 'review_status' => $deadline->review_status,
            'rule_code' => $deadline->rule_code, 'rule_version' => $deadline->rule_version,
            'record' => $deadline->ipRecord ? ['id' => $deadline->ipRecord->id, 'code' => $deadline->ipRecord->record_code, 'type' => $deadline->ipRecord->record_type, 'title' => $deadline->ipRecord->title] : null,
            'project' => $deadline->project ? ['id' => $deadline->project->id, 'docket_number' => $deadline->project->docket_number, 'name' => $deadline->project->project_name] : null,
            'client' => $deadline->project?->client ? ['id' => $deadline->project->client->id, 'code' => $deadline->project->client->client_code, 'name' => $deadline->project->client->company_name ?: $deadline->project->client->legal_name] : null,
        ];
    }
}
