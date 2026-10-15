<?php
// Creates N temporary load-test users (reserved @example.invalid domain, never mailed) with short-lived API
// tokens. Run on the server:  sudo -u www-data php artisan tinker --execute="require '/tmp/setup_users.php';"
// Output: /tmp/lt-tokens.json (mode 600). Remove everything afterwards with cleanup_users.php.
$n = (int) (getenv('LT_USERS') ?: 100);
$tokens = [];
for ($i = 1; $i <= $n; $i++) {
    $email = sprintf('loadtest+%03d@example.invalid', $i);
    $u = App\Models\User::query()->where('email', $email)->first() ?? App\Models\User::create([
        'name' => sprintf('Load Test %03d', $i),
        'email' => $email,
        'password' => Illuminate\Support\Str::random(40),
        'role' => 'partner',
        'status' => 'Active',
    ]);
    $tokens[] = $u->createToken('loadtest', ['*'], now()->addHours(4))->plainTextToken;
}
file_put_contents('/tmp/lt-tokens.json', json_encode($tokens));
chmod('/tmp/lt-tokens.json', 0600);
echo 'created '.count($tokens)." users\n";
