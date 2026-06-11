<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class CreateSuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'mugilvannan@myipstrategy.com'],
            [
                'name' => 'Mugil Vannan',
                'password' => bcrypt('admin123'),
                'role' => 'super_admin',
                'status' => 'Active',
            ]
        );

        echo "✓ Super Admin account created/updated:\n";
        echo "  Email: mugilvannan@myipstrategy.com\n";
        echo "  Password: admin123\n";
        echo "  Role: super_admin\n";
    }
}
