<?php
// Deletes the temporary load-test users, their tokens, notifications and firm memberships.
// Run on the server:  sudo -u www-data php artisan tinker --execute="require '/tmp/cleanup_users.php';"
$ids = App\Models\User::query()->where('email', 'like', 'loadtest+%@example.invalid')->pluck('id');
Illuminate\Support\Facades\DB::transaction(function () use ($ids) {
    Laravel\Sanctum\PersonalAccessToken::query()->where('tokenable_type', App\Models\User::class)->whereIn('tokenable_id', $ids)->delete();
    if (Illuminate\Support\Facades\Schema::hasTable('firm_memberships')) {
        Illuminate\Support\Facades\DB::table('firm_memberships')->whereIn('user_id', $ids)->delete();
    }
    App\Models\User::query()->whereIn('id', $ids)->delete();
});
@unlink('/tmp/lt-tokens.json');
echo 'removed '.count($ids)." users\n";
