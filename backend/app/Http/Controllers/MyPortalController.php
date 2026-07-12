<?php

namespace App\Http\Controllers;

use App\Mail\PortalInviteMail;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Self-service portal user management for client_admin users.
 * A client_admin can add/remove plain `client` users for their own company.
 */
class MyPortalController extends Controller
{
    private function resolveClientOrFail(Request $request): Client|\Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'client_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $client = $request->attributes->get('portal_client') ?? Client::forUser($user);
        if (! $client) {
            return response()->json(['message' => 'No client record linked to your account.'], 404);
        }
        return $client;
    }

    public function users(Request $request)
    {
        $client = $this->resolveClientOrFail($request);
        if ($client instanceof \Illuminate\Http\JsonResponse) return $client;

        $emails = $client->contacts()->pluck('email')->filter()->values();
        $users = User::query()
            ->where(function ($query) use ($emails, $client) {
                if ($emails->isNotEmpty()) {
                    $query->whereIn('email', $emails);
                }

                if ($client->portal_user_id) {
                    $method = $emails->isNotEmpty() ? 'orWhere' : 'where';
                    $query->{$method}('id', $client->portal_user_id);
                }
            })
            ->whereIn('role', User::CLIENT_ROLES)
            // Postgres: DISTINCT + ORDER BY raw expression is invalid — plain
            // column ordering ('client_admin' sorts after 'client', so desc
            // puts admins first) keeps both.
            ->orderByDesc('role')
            ->orderBy('name')
            ->distinct()
            ->get(['id', 'name', 'email', 'role', 'status', 'created_at']);

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $client = $this->resolveClientOrFail($request);
        if ($client instanceof \Illuminate\Http\JsonResponse) return $client;

        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'max:100', PasswordRule::min(8)->mixedCase()->symbols()],
        ]);

        $newUser = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role'     => 'client',
            'status'   => 'Active',
        ]);

        // Contact row links the new user to this client for all RBAC scoping.
        DB::table('client_contacts')->upsert(
            [['client_id' => $client->id, 'email' => $validated['email'], 'name' => $validated['name'], 'created_at' => now(), 'updated_at' => now()]],
            ['email'],
            ['client_id', 'name', 'updated_at']
        );

        // Credential emails disabled for now — the admin shares the password
        // directly. Re-enable by uncommenting.
        // try {
        //     Mail::to($validated['email'])->send(new PortalInviteMail(
        //         clientName: $validated['name'],
        //         email:      $validated['email'],
        //         password:   $validated['password'],
        //         loginUrl:   config('app.url') . '/login',
        //     ));
        // } catch (\Throwable) {}

        DB::table('ip_notifications')->insert([
            'user_id'     => $request->user()->id,
            'type'        => 'portal_invite',
            'title'       => 'Portal user added',
            'description' => "{$request->user()->name} added {$validated['name']} ({$validated['email']}) to the {$client->company_name} portal",
            'meta'        => json_encode(['client_id' => $client->id, 'user_id' => $newUser->id]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return response()->json([
            'ok'   => true,
            'user' => $newUser->only(['id', 'name', 'email', 'role', 'status', 'created_at']),
        ], 201);
    }

    public function destroy(Request $request, $userId)
    {
        $client = $this->resolveClientOrFail($request);
        if ($client instanceof \Illuminate\Http\JsonResponse) return $client;

        $target = User::findOrFail($userId);

        // Only plain client users of the same company can be removed —
        // never admins, never yourself, never firm staff.
        $belongs = $client->contacts()->where('email', $target->email)->exists();
        if (! $belongs || $target->role !== 'client' || $target->id === $request->user()->id) {
            return response()->json(['message' => 'You can only remove client users of your own company.'], 403);
        }

        $target->tokens()->delete();
        $client->contacts()->where('email', $target->email)->delete();
        $target->delete();

        return response()->json(['ok' => true]);
    }
}
