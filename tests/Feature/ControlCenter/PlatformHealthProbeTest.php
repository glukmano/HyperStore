<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformHealthProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_probe_returns_healthy_status_and_checks(): void
    {
        $response = $this->getJson(route('control-center.health'));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'timestamp',
            'checks' => [
                'database' => ['status', 'message'],
                'cache' => ['status', 'message'],
            ],
        ]);
        $this->assertSame('healthy', $response->json('status'));
        $this->assertSame('ok', $response->json('checks.database.status'));
        $this->assertSame('ok', $response->json('checks.cache.status'));
    }
}
