<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\DiscussionThread;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class CaseDiscussions extends Tool
{
    public function name(): string { return 'case_discussions'; }

    public function title(): string { return 'Case discussions'; }

    public function description(): string
    {
        return 'Discussion threads and chat messages on one case the user can see (newest first, latest 30 messages per thread): author, time and text. Private threads are not included.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'case_id' => ['type' => 'integer'],
            'docket_number' => ['type' => 'string'],
        ]];
    }

    public function handle(User $user, array $args): array
    {
        $case = ! empty($args['case_id']) ? Project::findOrFail((int) $args['case_id'])
            : Project::where('docket_number', (string) $this->str($args, 'docket_number'))->firstOrFail();
        Gate::forUser($user)->authorize('view', $case);

        $threads = DiscussionThread::where('project_id', $case->id)->where(fn ($q) => $q->whereNull('is_private')->orWhere('is_private', false))
            ->with(['messages' => fn ($m) => $m->with('author:id,name')->latest()->limit(30)])->orderByDesc('updated_at')->limit(10)->get();

        return ['case' => $case->docket_number, 'threads' => $threads->map(fn ($t) => [
            'title' => $t->title, 'kind' => $t->kind, 'status' => $t->status, 'updated' => self::date($t->updated_at),
            'messages' => $t->messages->sortBy('created_at')->map(fn ($m) => [
                'author' => $m->author?->name, 'at' => $m->created_at?->timezone('Asia/Kolkata')->format('Y-m-d H:i'), 'text' => mb_substr((string) $m->content, 0, 2000),
            ])->values()->all(),
        ])->all()];
    }
}
