<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracker_rows', function (Blueprint $table) {
            $table->unsignedBigInteger('pcm_id')->nullable()->after('record_type');
            $table->unsignedBigInteger('scm_id')->nullable()->after('pcm_id');
            $table->unsignedBigInteger('pr_id')->nullable()->after('scm_id');

            $table->foreign('pcm_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('scm_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('pr_id')->references('id')->on('users')->nullOnDelete();
        });

        // Backfill IDs from existing name strings (DB-agnostic via Eloquent)
        DB::table('tracker_rows')->whereNotNull('pcm')->where('pcm', '!=', '')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $user = DB::table('users')->whereRaw('LOWER(name) = LOWER(?)', [trim($row->pcm)])->first();
                if ($user) DB::table('tracker_rows')->where('id', $row->id)->update(['pcm_id' => $user->id]);
            }
        });
        DB::table('tracker_rows')->whereNotNull('scm')->where('scm', '!=', '')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $user = DB::table('users')->whereRaw('LOWER(name) = LOWER(?)', [trim($row->scm)])->first();
                if ($user) DB::table('tracker_rows')->where('id', $row->id)->update(['scm_id' => $user->id]);
            }
        });
        DB::table('tracker_rows')->whereNotNull('pr')->where('pr', '!=', '')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $user = DB::table('users')->whereRaw('LOWER(name) = LOWER(?)', [trim($row->pr)])->first();
                if ($user) DB::table('tracker_rows')->where('id', $row->id)->update(['pr_id' => $user->id]);
            }
        });

        Schema::table('tracker_rows', function (Blueprint $table) {
            $table->dropColumn(['pcm', 'scm', 'pr']);
        });
    }

    public function down(): void
    {
        Schema::table('tracker_rows', function (Blueprint $table) {
            $table->string('pcm')->nullable()->after('record_type');
            $table->string('scm')->nullable()->after('pcm');
            $table->string('pr')->nullable()->after('scm');
        });

        // Restore names from IDs (DB-agnostic via Eloquent)
        DB::table('tracker_rows')->whereNotNull('pcm_id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $user = DB::table('users')->where('id', $row->pcm_id)->first();
                if ($user) DB::table('tracker_rows')->where('id', $row->id)->update(['pcm' => $user->name]);
            }
        });
        DB::table('tracker_rows')->whereNotNull('scm_id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $user = DB::table('users')->where('id', $row->scm_id)->first();
                if ($user) DB::table('tracker_rows')->where('id', $row->id)->update(['scm' => $user->name]);
            }
        });
        DB::table('tracker_rows')->whereNotNull('pr_id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $user = DB::table('users')->where('id', $row->pr_id)->first();
                if ($user) DB::table('tracker_rows')->where('id', $row->id)->update(['pr' => $user->name]);
            }
        });

        Schema::table('tracker_rows', function (Blueprint $table) {
            $table->dropForeign(['pcm_id']);
            $table->dropForeign(['scm_id']);
            $table->dropForeign(['pr_id']);
            $table->dropColumn(['pcm_id', 'scm_id', 'pr_id']);
        });
    }
};
