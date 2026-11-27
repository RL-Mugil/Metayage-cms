<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use App\Services\ClientPortalUsers;
use App\Support\ClientAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Self-service portal users for a client's own logins with "Manage users & designations":
 * add a colleague with a designation (ready-made or the client's own), remove one. Same rules
 * and audit trail as the firm's Client Portal page (App\Services\ClientPortalUsers).
 */
class MyPortalController extends Controller
{
    private function resolveClientOrFail(Request $request): Client|\Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (! $user->isClientRole() || ! ClientAccess::can($user, 'manage_users')) {
            return response()->json(['message' => 'Your portal access does not include managing users.'], 403);
        }
        $client = $request->attributes->get('portal_client') ?? Client::forUser($user);
        if (! $client) {
            return response()->json(['message' => 'No client record linked to your account.'], 404);
        }

        return $client;
    }

    public function users(Request $request, ClientPortalUsers $portalUsers)
    {
        $client = $this->resolveClientOrFail($request);
        if ($client instanceof \Illuminate\Http\JsonResponse) return $client;

        return response()->json($portalUsers->list($client));
    }

    public function store(Request $request, ClientPortalUsers $portalUsers)
    {
        $client = $this->resolveClientOrFail($request);
        if ($client instanceof \Illuminate\Http\JsonResponse) return $client;

        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'phone'    => 'nullable|string|max:30',
            'password' => ['required', 'max:100', PasswordRule::min(8)->mixedCase()->symbols()],
            'designation_id' => 'nullable|integer',
            // Old form: a plain access level, mapped to the matching ready-made designation.
            'role'     => 'nullable|string|in:client,client_finance',
        ]);
        if (empty($validated['designation_id'])) {
            $validated['designation'] = ($validated['role'] ?? 'client') === 'client_finance' ? 'Accounts Person' : 'Other';
        }
        $designation = ClientPortalUsers::designationFor($client, $validated);
        // Inventor logins need their Form 1 particulars: the firm adds those.
        abort_if($designation->is_inventor, 422, 'Ask the firm to add an inventor login (their Form 1 details are needed).');

        $row = $portalUsers->add($client, $validated, $request->user(), $request->ip(), $request->userAgent());

        return response()->json(['ok' => true, 'user' => $row], 201);
    }

    public function destroy(Request $request, $userId, ClientPortalUsers $portalUsers)
    {
        $client = $this->resolveClientOrFail($request);
        if ($client instanceof \Illuminate\Http\JsonResponse) return $client;

        $target = User::findOrFail($userId);
        if ($target->id === $request->user()->id || ! $target->isClientRole()) {
            return response()->json(['message' => 'You can only remove other users of your own company.'], 403);
        }
        $portalUsers->remove($client, $target, $request->user(), $request->ip());

        return response()->json(['ok' => true]);
    }
}
