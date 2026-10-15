<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Unauthenticated liveness/readiness probe for the uptime monitor and load tests.
 * Reports only ok/fail per dependency: no versions, hostnames or data.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->probe(fn () => DB::select('select 1')),
            'redis'    => $this->probe(fn () => Redis::connection()->ping()),
        ];
        $ok = ! in_array(false, $checks, true);

        return response()->json(['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks], $ok ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    private function probe(callable $fn): bool
    {
        try {
            $fn();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
