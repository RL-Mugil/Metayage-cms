<?php

use App\Support\ClientAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client portal designations with access checkboxes. Ready-made ones (Manager, CFO, CTO,
 * Accounts Person, Inventor, Other) are created; every existing client login gets the
 * designation already on its contact (when that keeps the same role), otherwise the one for
 * its old role: client_admin = Manager, client_finance = Accounts Person, client = Other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_designations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('permissions');
            $table->boolean('is_inventor')->default(false);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['client_id', 'name']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('client_designation_id')->nullable()->constrained('client_designations')->nullOnDelete();
        });

        $ids = [];
        foreach (ClientAccess::PRESETS as $name => $permissions) {
            $ids[$name] = DB::table('client_designations')->insertGetId(['client_id' => null, 'name' => $name, 'permissions' => json_encode($permissions),
                'is_inventor' => $name === 'Inventor', 'created_at' => now(), 'updated_at' => now()]);
        }
        $byRole = ['client_admin' => 'Manager', 'client_finance' => 'Accounts Person', 'client' => 'Other', 'inventor' => 'Inventor'];
        foreach (DB::table('users')->whereIn('role', array_keys($byRole))->get(['id', 'email', 'role']) as $u) {
            $contactDesignation = DB::table('client_contacts')->whereRaw('LOWER(email) = ?', [strtolower((string) $u->email)])->value('designation');
            // The contact's designation only if it gives the same role (scoping stays the same).
            $name = $byRole[$u->role];
            if ($contactDesignation && isset($ids[$contactDesignation]) && ClientAccess::roleFor(ClientAccess::PRESETS[$contactDesignation], $contactDesignation === 'Inventor') === $u->role) {
                $name = $contactDesignation;
            }
            DB::table('users')->where('id', $u->id)->update(['client_designation_id' => $ids[$name]]);
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('client_designation_id'));
        Schema::dropIfExists('client_designations');
    }
};
