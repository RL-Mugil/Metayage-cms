<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Client;
use App\Services\GovApi\EntityLockerVerificationService;
use Illuminate\Http\Request;

/** Triggers EntityLocker business-document verification for one client — see EntityLockerVerificationService. */
class EntityLockerController extends Controller
{
    private const MANAGE_ROLES = ['super_admin', 'partner', 'manager', 'galvanizer'];

    public function __construct(private EntityLockerVerificationService $service)
    {
    }

    public function verify(Request $request, $clientId)
    {
        $user = $request->user();
        if (! in_array($user->role, self::MANAGE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $client = Client::findOrFail($clientId);

        try {
            $result = $this->service->verify($client);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'verify_client_entity',
            'subject_type' => 'Client', 'subject_id' => $client->id,
            'metadata' => $result,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json($result);
    }
}
