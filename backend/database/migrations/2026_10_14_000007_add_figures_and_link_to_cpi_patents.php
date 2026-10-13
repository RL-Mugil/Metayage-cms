<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cpi_patents', function (Blueprint $table) {
            $table->json('figures')->nullable()->after('image_alt');          // [{"url": "/insights/img/x.webp", "alt": "..."}, ...]
            $table->string('link_url', 300)->nullable()->after('figures');    // optional override; blank = automatic Google Patents link
            $table->boolean('link_disabled')->default(false)->after('link_url');
        });

        // Existing single figures become the first (and only) entry of the list.
        foreach (DB::table('cpi_patents')->whereNotNull('image')->get(['id', 'image', 'image_alt']) as $row) {
            DB::table('cpi_patents')->where('id', $row->id)->update([
                'figures' => json_encode([['url' => $row->image, 'alt' => $row->image_alt]], JSON_UNESCAPED_SLASHES),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('cpi_patents', function (Blueprint $table) {
            $table->dropColumn(['figures', 'link_url', 'link_disabled']);
        });
    }
};
