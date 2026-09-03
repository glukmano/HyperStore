<?php

declare(strict_types=1);

namespace Tests\Unit\ControlCenter;

use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\VendorContext;
use Tests\TestCase;

class ContextManagerVendorContextTest extends TestCase
{
    public function test_vendor_context_defaults_to_unresolved(): void
    {
        $manager = new ContextManager;

        $this->assertFalse($manager->hasVendor());
        $this->assertNull($manager->getVendor()->getVendorId());
        $this->assertNull($manager->getVendor()->getVendorUuid());
    }

    public function test_vendor_context_can_be_set_and_reset(): void
    {
        $manager = new ContextManager;
        $vendorCtx = new VendorContext(42, 'v-uuid-42');

        $manager->setVendor($vendorCtx);

        $this->assertTrue($manager->hasVendor());
        $this->assertSame(42, $manager->getVendor()->getVendorId());
        $this->assertSame('v-uuid-42', $manager->getVendor()->getVendorUuid());

        $manager->reset();

        $this->assertFalse($manager->hasVendor());
        $this->assertNull($manager->getVendor()->getVendorId());
    }
}
