<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class ReminderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $due = Carbon::parse($this->due_date);
        $today = Carbon::today();
        $section = $due->lte($today) ? 'today' : ($due->lte($today->copy()->addDays(7)) ? 'week' : 'upcoming');

        return [
            'id' => $this->id, 'title' => $this->title, 'description' => $this->description ?? '',
            'category' => $this->category, 'dueDate' => $due->toDateString(), 'dueTime' => $this->due_time,
            'assignedTo' => $this->assigned_user_id === $request->user()->id ? 'You' : ($this->assignee?->name ?? ($this->scope === 'team' ? 'Team' : 'You')),
            'assignedUserId' => $this->assigned_user_id, 'completed' => $this->completed,
            'acknowledged' => $this->acknowledged_at !== null, 'section' => $section,
            'source' => $this->source, 'docketDeadlineId' => $this->docket_deadline_id,
        ];
    }
}
