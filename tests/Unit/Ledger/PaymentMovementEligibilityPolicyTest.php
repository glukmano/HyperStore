<?php

declare(strict_types=1);

namespace Tests\Unit\Ledger;

use Modules\Ledger\Policies\PaymentMovementEligibilityPolicy;
use PHPUnit\Framework\TestCase;

class PaymentMovementEligibilityPolicyTest extends TestCase
{
    private PaymentMovementEligibilityPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new PaymentMovementEligibilityPolicy;
    }

    public function test_purchase_and_capture_with_success_and_positive_amount_are_eligible(): void
    {
        $this->assertTrue($this->policy->isEligible('purchase', 'success', 5000));
        $this->assertSame('capture', $this->policy->resolvePostingType('purchase'));

        $this->assertTrue($this->policy->isEligible('capture', 'success', 5000));
        $this->assertSame('capture', $this->policy->resolvePostingType('capture'));
    }

    public function test_refund_with_success_and_positive_amount_is_eligible(): void
    {
        $this->assertTrue($this->policy->isEligible('refund', 'success', 3000));
        $this->assertSame('refund', $this->policy->resolvePostingType('refund'));
    }

    public function test_ineligible_operations_return_false(): void
    {
        $this->assertFalse($this->policy->isEligible('authorize', 'success', 5000));
        $this->assertFalse($this->policy->isEligible('void', 'success', 5000));
        $this->assertFalse($this->policy->isEligible('zero_total_settlement', 'success', 0));
        $this->assertFalse($this->policy->isEligible('purchase', 'failure', 5000));
        $this->assertFalse($this->policy->isEligible('purchase', 'unknown', 5000));
        $this->assertFalse($this->policy->isEligible('purchase', 'pending', 5000));
        $this->assertFalse($this->policy->isEligible('purchase', 'action_required', 5000));
        $this->assertFalse($this->policy->isEligible('purchase', 'success', 0));
        $this->assertFalse($this->policy->isEligible('purchase', 'success', -100));
    }
}
