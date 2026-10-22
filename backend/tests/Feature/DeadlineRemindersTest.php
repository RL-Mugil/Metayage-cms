<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeadlineRemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_required_document_nag_creates_a_reminder_with_a_due_date_and_does_not_crash_the_command(): void
    {
        $user = User::create(['name' => 'Engineer', 'email' => 'eng@test.local', 'password' => bcrypt('password'), 'role' => 'associate', 'status' => 'Active']);
        $client = Client::create(['company_name' => 'Test Client', 'client_code' => 'C01M', 'entity_type' => 'Corporation', 'industry' => 'Tech', 'status' => 'Active']);
        $project = Project::create(['project_code' => '397M020INREN', 'docket_number' => '397M020INREN', 'project_name' => 'Renewal', 'project_type' => 'Patent',
            'client_id' => $client->id, 'status' => 'Active', 'patent_engineer_id' => $user->id]);
        $task = Task::create(['project_id' => $project->id, 'title' => 'Upload Granted Order', 'assignee_id' => $user->id, 'priority' => 'Normal',
            'due_date' => now()->subDays(9), 'status' => 'Not Started',
            'tags' => ['required_document_category' => 'Granted Order', 'context_label' => 'Granted Order']]);

        $this->artisan('reminders:send-deadlines')->assertSuccessful();

        $reminder = Reminder::where('source', "required-document:{$task->id}")->firstOrFail();
        $this->assertNotNull($reminder->due_date, 'reminders.due_date is NOT NULL; an open-ended nag is due today');
        $this->assertSame(now()->toDateString(), $reminder->due_date->toDateString());
        $this->assertSame('Document Required', $reminder->category);
        $this->assertSame(1, DB::table('ip_notifications')->where('user_id', $user->id)->where('type', 'document_required')->count());

        // running it again the same day updates the reminder instead of failing or duplicating it
        $this->artisan('reminders:send-deadlines')->assertSuccessful();
        $this->assertSame(1, Reminder::where('source', "required-document:{$task->id}")->count());
    }
}
