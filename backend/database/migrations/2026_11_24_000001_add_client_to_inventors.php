<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventors are managed under the client they work for (the client panel's Inventors
 * tab) and the case form picks from that client's inventors. Existing inventors take
 * the client of the first case they were named on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventors', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('firm_id')->constrained('clients')->nullOnDelete();
            $table->index(['client_id', 'full_name']);
        });

        $firstCase = DB::table('project_inventors as pi')
            ->join('projects as p', 'p.id', '=', 'pi.project_id')
            ->whereNotNull('p.client_id')
            ->orderBy('pi.id')
            ->get(['pi.inventor_id', 'p.client_id']);
        $assigned = [];
        foreach ($firstCase as $row) {
            if ($row->inventor_id && ! isset($assigned[$row->inventor_id])) {
                $assigned[$row->inventor_id] = true;
                DB::table('inventors')->where('id', $row->inventor_id)->update(['client_id' => $row->client_id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('inventors', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'full_name']);
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
