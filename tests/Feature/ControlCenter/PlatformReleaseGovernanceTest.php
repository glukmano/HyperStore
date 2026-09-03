<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Core\SuperAdmin\Contracts\PlatformReleaseServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformReleaseGovernanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_release_lifecycle(): void
    {
        $service = app(PlatformReleaseServiceInterface::class);

        // 1. Create Draft
        $release = $service->createRelease(
            version: '1.5.0',
            channel: 'stable',
            notes: 'Initial release notes',
            compatibility: ['min_php' => '8.4', 'min_laravel' => '13.0']
        );
        $this->assertSame('draft', $release->status);

        // 2. Publish
        $published = $service->publishRelease($release->id);
        $this->assertSame('published', $published->status);
        $this->assertNotNull($published->published_at);

        // 3. Withdraw
        $withdrawn = $service->withdrawRelease($release->id);
        $this->assertSame('withdrawn', $withdrawn->status);
        $this->assertNotNull($withdrawn->withdrawn_at);
    }
}
