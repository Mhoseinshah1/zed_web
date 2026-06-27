<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        $checks = [
            'app'        => true,
            'database'   => false,
            'redis'      => false,
            'migrations' => false,
            'storage'    => false,
        ];

        $errors = [];

        // Database check
        try {
            DB::connection()->getPdo();
            $checks['database'] = true;
        } catch (\Throwable $e) {
            $errors['database'] = 'Cannot connect to database: ' . $e->getMessage();
        }

        // Migrations table check
        if ($checks['database']) {
            try {
                DB::table('migrations')->count();
                $checks['migrations'] = true;
            } catch (\Throwable $e) {
                $errors['migrations'] = 'Migrations table not accessible: ' . $e->getMessage();
            }
        }

        // Redis check
        try {
            Cache::store('redis')->put('health_ping', 'ok', 5);
            $ping = Cache::store('redis')->get('health_ping');
            $checks['redis'] = $ping === 'ok';
            if (! $checks['redis']) {
                $errors['redis'] = 'Redis ping failed';
            }
        } catch (\Throwable $e) {
            $errors['redis'] = 'Cannot connect to Redis: ' . $e->getMessage();
        }

        // Storage check
        try {
            Storage::disk('local')->put('.health_check', 'ok');
            Storage::disk('local')->delete('.health_check');
            $checks['storage'] = true;
        } catch (\Throwable $e) {
            $errors['storage'] = 'Storage not writable: ' . $e->getMessage();
        }

        $allOk = ! in_array(false, $checks, strict: true);

        $response = [
            'status' => $allOk ? 'ok' : 'error',
            ...$checks,
        ];

        if (! empty($errors)) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $allOk ? 200 : 503);
    }
}
