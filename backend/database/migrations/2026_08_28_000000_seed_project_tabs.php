<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $tabs = [
            ['key' => 'prior_art_search',     'label' => 'Prior Art Search',     'codes' => ['PAS', 'FTO'],       'is_system' => false],
            ['key' => 'provisional',          'label' => 'Provisional',          'codes' => ['PRV'],              'is_system' => false],
            ['key' => 'complete_application', 'label' => 'Complete Application', 'codes' => ['CPT'],              'is_system' => false],
            ['key' => 'examination',          'label' => 'Examination',          'codes' => ['FER', 'SER', 'TER'], 'is_system' => false],
            ['key' => 'hearing',              'label' => 'Hearing',              'codes' => ['HRG'],              'is_system' => false],
            ['key' => 'others',               'label' => 'Others',               'codes' => [],                   'is_system' => true],
        ];

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'project_tabs'],
            ['value' => json_encode($tabs), 'created_at' => $now, 'updated_at' => $now]
        );
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'project_tabs')->delete();
    }
};
