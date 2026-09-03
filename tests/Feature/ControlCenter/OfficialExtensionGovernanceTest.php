<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Core\SuperAdmin\Contracts\OfficialExtensionGovernanceServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfficialExtensionGovernanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_extension_governance_lifecycle(): void
    {
        $service = app(OfficialExtensionGovernanceServiceInterface::class);

        // 1. Register Draft
        $ext = $service->registerExtension(
            slug: 'hyper-shipping-fedex',
            name: 'FedEx Official Shipping',
            publisher: 'FedEx Corp',
            category: 'shipping'
        );
        $this->assertSame('draft', $ext->status);

        // 2. Approve
        $approved = $service->approveExtension($ext->id, '1.0.0');
        $this->assertSame('approved', $approved->status);
        $this->assertSame('1.0.0', $approved->approved_version);

        // 3. Publish
        $published = $service->publishExtension($ext->id);
        $this->assertSame('published', $published->status);

        // 4. Suspend
        $suspended = $service->suspendExtension($ext->id);
        $this->assertSame('suspended', $suspended->status);
    }
}
