<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** HRMS → Employees: A→Z, 25 per page, with search and status filter on the server. */
class EmployeeDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::create(['name' => 'HR', 'email' => 'hr@firm.test', 'password' => bcrypt('password'), 'role' => 'hr', 'status' => 'Active']));
    }

    private function employee(string $name, string $status = 'Active', ?int $departmentId = null): Employee
    {
        static $n = 0;
        $n++;

        return Employee::create([
            'employee_code'     => sprintf('EMP-T-%04d', $n),
            'full_name'         => $name,
            'work_email'        => 'e'.$n.'@firm.test',
            'employment_status' => $status,
            'department_id'     => $departmentId,
            'date_of_joining'   => now()->subYear(),
        ]);
    }

    public function test_directory_is_alphabetical_and_paged_by_25(): void
    {
        foreach (range(1, 30) as $i) {
            $this->employee(sprintf('person %02d', 31 - $i));   // created Z→A on purpose
        }
        $this->employee('Aaron First');

        $first = $this->getJson('/api/hrms/employees?page=1&per_page=25')->assertOk()
            ->assertJsonPath('total', 31)->assertJsonPath('last_page', 2)->assertJsonCount(25, 'data');
        $this->assertSame('Aaron First', $first->json('data.0.full_name'));
        $this->assertSame('person 01', $first->json('data.1.full_name'));

        $this->getJson('/api/hrms/employees?page=2&per_page=25')->assertOk()
            ->assertJsonCount(6, 'data')->assertJsonPath('data.0.full_name', 'person 25')->assertJsonPath('data.5.full_name', 'person 30');
    }

    public function test_search_covers_department_and_status_filters_on_the_server(): void
    {
        $ip = Department::create(['name' => 'Patent Drafting']);
        $this->employee('Ravi', 'Active', $ip->id);
        $this->employee('Meena');
        $this->employee('Old Hand', 'Resigned');

        $this->getJson('/api/hrms/employees?search=drafting')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.full_name', 'Ravi');
        $this->getJson('/api/hrms/employees?employment_status=Active')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/hrms/employees?employment_status=Resigned')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.full_name', 'Old Hand');
    }
}
