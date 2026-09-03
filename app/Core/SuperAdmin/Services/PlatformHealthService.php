<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Services;

use App\Core\SuperAdmin\Contracts\PlatformHealthServiceInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class PlatformHealthService implements PlatformHealthServiceInterface
{
    public function checkHealth(): array
    {
        $checks = [];
        $overallHealthy = true;

        // 1. Database Check
        try {
            DB::connection()->getPdo();
            $checks['database'] = ['status' => 'ok', 'message' => null];
        } catch (Throwable $e) {
            $overallHealthy = false;
            $checks['database'] = ['status' => 'error', 'message' => $e->getMessage()];
        }

        // 2. Cache Check
        try {
            Cache::put('__health_probe__', true, 10);
            $cacheVal = Cache::get('__health_probe__');
            if ($cacheVal === true) {
                $checks['cache'] = ['status' => 'ok', 'message' => null];
            } else {
                $overallHealthy = false;
                $checks['cache'] = ['status' => 'error', 'message' => 'Cache probe read mismatch'];
            }
        } catch (Throwable $e) {
            $overallHealthy = false;
            $checks['cache'] = ['status' => 'error', 'message' => $e->getMessage()];
        }

        return [
            'status' => $overallHealthy ? 'healthy' : 'degraded',
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ];
    }
}
