<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Department;
use App\Models\Designation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed roles
        $roles = [
            ['name' => 'super_admin', 'guard_name' => 'web'],
            ['name' => 'partner',     'guard_name' => 'web'],
            ['name' => 'manager',     'guard_name' => 'web'],
            ['name' => 'associate',   'guard_name' => 'web'],
            ['name' => 'paralegal',   'guard_name' => 'web'],
            ['name' => 'finance',     'guard_name' => 'web'],
            ['name' => 'hr',          'guard_name' => 'web'],
            ['name' => 'galvanizer',  'guard_name' => 'web'],
            ['name' => 'client',      'guard_name' => 'web'],
        ];
        foreach ($roles as $role) {
            \DB::table('roles')->updateOrInsert(['name' => $role['name']], $role);
        }

        // 2. Seed departments
        $depts = [
            ['id' => 1, 'name' => 'Patents'],
            ['id' => 2, 'name' => 'Trademarks'],
            ['id' => 3, 'name' => 'Litigation'],
            ['id' => 4, 'name' => 'Finance'],
            ['id' => 5, 'name' => 'People Ops'],
        ];
        foreach ($depts as $d) {
            Department::updateOrInsert(['id' => $d['id']], ['name' => $d['name']]);
        }

        // 3. Seed designations
        $desigs = [
            ['id' => 1, 'title' => 'Partner',          'grade_band' => 'L7'],
            ['id' => 2, 'title' => 'Senior Associate',  'grade_band' => 'L5'],
            ['id' => 3, 'title' => 'Associate',          'grade_band' => 'L3'],
            ['id' => 4, 'title' => 'Paralegal',          'grade_band' => 'L2'],
            ['id' => 5, 'title' => 'Junior Associate',   'grade_band' => 'L1'],
            ['id' => 6, 'title' => 'HR Manager',         'grade_band' => 'L4'],
            ['id' => 7, 'title' => 'Finance Lead',       'grade_band' => 'L4'],
        ];
        foreach ($desigs as $d) {
            Designation::updateOrInsert(['id' => $d['id']], $d);
        }

        // 4. Create admin user
        $admin = User::updateOrCreate(
            ['email' => 'mugilvannan@myipstrategy.com'],
            [
                'name'     => 'Mugilvannan',
                'password' => Hash::make('admin123'),
                'role'     => 'super_admin',
                'status'   => 'Active',
            ]
        );

        $adminRoleId = \DB::table('roles')->where('name', 'super_admin')->value('id');
        \DB::table('model_has_roles')->updateOrInsert(
            ['model_type' => 'App\Models\User', 'model_id' => $admin->id],
            ['role_id' => $adminRoleId, 'model_type' => 'App\Models\User', 'model_id' => $admin->id]
        );
    }
}
