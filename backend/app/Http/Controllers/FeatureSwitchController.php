<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Firm-wide on/off switches kept in system_settings: the weekly digest email and the team
 * leaderboard. Anyone signed in can read them; only a super admin can change them.
 */
class FeatureSwitchController extends Controller
{
    /** key => default */
    public const SWITCHES = ['weekly_digest_enabled' => false, 'leaderboard_enabled' => false];

    public static function on(string $key): bool
    {
        $value = DB::table('system_settings')->where('key', $key)->value('value');

        return $value === null ? (self::SWITCHES[$key] ?? false) : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function index(): JsonResponse
    {
        return response()->json(collect(self::SWITCHES)->keys()->mapWithKeys(fn ($key) => [$key => self::on($key)]));
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'super_admin', 403);
        $validated = $request->validate(collect(self::SWITCHES)->keys()->mapWithKeys(fn ($key) => [$key => 'sometimes|boolean'])->all());
        DB::transaction(function () use ($validated, $request) {
            foreach ($validated as $key => $value) {
                DB::table('system_settings')->updateOrInsert(['key' => $key], ['value' => $value ? '1' : '0', 'updated_at' => now()]);
            }
            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'update_feature_switches', 'subject_type' => 'SystemSetting', 'subject_id' => 0,
                'metadata' => $validated, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
        });

        return $this->index();
    }
}
