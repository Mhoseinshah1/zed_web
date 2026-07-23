<?php

namespace App\Http\Controllers;

use App\Services\Health\HealthCheckService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __construct(private readonly HealthCheckService $health) {}

    /**
     * Public readiness probe. Returns ONLY safe boolean information — never
     * exception messages, stack traces, file paths, hostnames, endpoints, or
     * credentials. Returns 503 when any component is unhealthy.
     */
    public function check(): JsonResponse
    {
        $components = $this->health->booleans();
        $allOk = ! in_array(false, $components, strict: true);

        // Explicit, fixed key order — booleans only.
        $payload = [
            'status' => $allOk ? 'ok' : 'error',
            'app' => $components['app'],
            'database' => $components['database'],
            'redis' => $components['redis'],
            'migrations' => $components['migrations'],
            'storage' => $components['storage'],
        ];

        return response()->json($payload, $allOk ? 200 : 503);
    }

    /**
     * Liveness probe — the process is up and can serve a request. Does not touch
     * the database, Redis, or storage, so an unready-but-alive instance is not
     * killed by orchestrators. Always safe/boolean.
     */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok', 'app' => true], 200);
    }
}
