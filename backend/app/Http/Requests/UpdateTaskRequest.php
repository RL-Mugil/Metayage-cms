<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user->isClientRole()) return false;
        if (in_array($user->role, ['associate', 'paralegal'])) {
            $task = \App\Models\Task::find($this->route('id'));
            return $task && $task->assignee_id === $user->id;
        }
        return true;
    }

    public function rules(): array
    {
        return [
            'title'        => 'sometimes|required|string|max:255',
            'description'  => 'nullable|string',
            'project_id'   => 'sometimes|exists:projects,id',
            'assignee_id'  => 'nullable|exists:users,id',
            'status'       => 'sometimes|required|string',
            'priority'     => 'sometimes|required|string',
            'due_date'     => 'nullable|date',
            'actual_hours' => 'nullable|numeric',
        ];
    }
}
