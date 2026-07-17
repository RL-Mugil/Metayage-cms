<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('invention_number', 3)->nullable()->after('docket_number');
            $table->index(['client_id', 'invention_number']);
        });

        DB::table('projects')
            ->join('clients', 'clients.id', '=', 'projects.client_id')
            ->select('projects.id', 'projects.docket_number', 'clients.client_code')
            ->orderBy('projects.id')
            ->chunk(250, function ($rows) {
                foreach ($rows as $row) {
                    $docket = strtoupper(trim((string) $row->docket_number));
                    $clientCode = strtoupper(trim((string) $row->client_code));
                    if (str_starts_with($docket, $clientCode) && strlen($docket) === strlen($clientCode) + 8) {
                        $number = substr($docket, strlen($clientCode), 3);
                        if (preg_match('/^\d{3}$/', $number) && $number !== '000') {
                            DB::table('projects')->where('id', $row->id)->update(['invention_number' => $number]);
                        }
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'invention_number']);
            $table->dropColumn('invention_number');
        });
    }
};
