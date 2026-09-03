<?php

declare(strict_types=1);

namespace Tests\Unit\ControlCenter;

use App\Core\Tenancy\Enums\TenantOperationalStatus;
use Tests\TestCase;

class TenantLifecycleStateMachineTest extends TestCase
{
    public function test_valid_lifecycle_transitions(): void
    {
        $this->assertTrue(TenantOperationalStatus::Provisioning->canTransitionTo(TenantOperationalStatus::Active));
        $this->assertTrue(TenantOperationalStatus::Active->canTransitionTo(TenantOperationalStatus::Suspended));
        $this->assertTrue(TenantOperationalStatus::Suspended->canTransitionTo(TenantOperationalStatus::Active));
        $this->assertTrue(TenantOperationalStatus::Active->canTransitionTo(TenantOperationalStatus::Terminated));
        $this->assertTrue(TenantOperationalStatus::Suspended->canTransitionTo(TenantOperationalStatus::Terminated));
    }

    public function test_terminated_is_strictly_terminal(): void
    {
        $terminated = TenantOperationalStatus::Terminated;

        $this->assertTrue($terminated->isTerminal());
        $this->assertFalse($terminated->canTransitionTo(TenantOperationalStatus::Active));
        $this->assertFalse($terminated->canTransitionTo(TenantOperationalStatus::Suspended));
        $this->assertFalse($terminated->canTransitionTo(TenantOperationalStatus::Provisioning));
    }
}
