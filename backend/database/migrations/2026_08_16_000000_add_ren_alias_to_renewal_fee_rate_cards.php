<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Real project data uses service_code 'REN' for renewals — confirmed by direct
// query (17 projects, 'REN'; zero projects, 'RNF'). 'RNF' is what
// config/project_import_codes.php's dictionary calls it, which the fee_rate_cards
// seed trusted without checking actual usage. Duplicate every seeded RNF row
// (IN + EP) under 'REN' too, so lookups work regardless of which code a given
// case (old or new) actually carries.
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('fee_rate_cards')->where('service_code', 'RNF')->get();
        $now = now();

        foreach ($rows as $row) {
            $clone = (array) $row;
            unset($clone['id']);
            $clone['service_code'] = 'REN';
            $clone['created_at'] = $now;
            $clone['updated_at'] = $now;
            DB::table('fee_rate_cards')->insert($clone);
        }
    }

    public function down(): void
    {
        DB::table('fee_rate_cards')->where('service_code', 'REN')->delete();
    }
};
