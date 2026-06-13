<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PublicHolidaysSeeder extends Seeder
{
    public function run(): void
    {
        $holidays = [
            // Indian national gazetted holidays 2026
            ['date' => '2026-01-26', 'name' => 'Republic Day'],
            ['date' => '2026-03-02', 'name' => 'Holi'],
            ['date' => '2026-03-30', 'name' => 'Eid ul-Fitr (Ramzan)'],
            ['date' => '2026-04-02', 'name' => 'Ram Navami'],
            ['date' => '2026-04-03', 'name' => 'Good Friday'],
            ['date' => '2026-04-14', 'name' => 'Dr. Ambedkar Jayanti'],
            ['date' => '2026-05-25', 'name' => 'Buddha Purnima'],
            ['date' => '2026-06-07', 'name' => 'Eid ul-Adha (Bakrid)'],
            ['date' => '2026-07-06', 'name' => 'Muharram'],
            ['date' => '2026-08-15', 'name' => 'Independence Day'],
            ['date' => '2026-09-14', 'name' => 'Milad-un-Nabi (Prophet\'s Birthday)'],
            ['date' => '2026-10-02', 'name' => 'Gandhi Jayanti'],
            ['date' => '2026-10-20', 'name' => 'Dussehra (Vijayadashami)'],
            ['date' => '2026-11-08', 'name' => 'Diwali (Deepavali)'],
            ['date' => '2026-11-09', 'name' => 'Diwali Holiday'],
            ['date' => '2026-11-15', 'name' => 'Guru Nanak Jayanti'],
            ['date' => '2026-12-25', 'name' => 'Christmas Day'],
        ];

        foreach ($holidays as $holiday) {
            DB::table('public_holidays')->updateOrInsert(
                ['date' => $holiday['date'], 'country' => 'IN'],
                ['name' => $holiday['name'], 'country' => 'IN', 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }
}
