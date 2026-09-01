<?php

declare(strict_types=1);

namespace Modules\Checkout\Services;

use Modules\Checkout\Contracts\CheckoutMutationBarrierInterface;
use Modules\Checkout\Models\CheckoutSession;

class NoOpCheckoutMutationBarrier implements CheckoutMutationBarrierInterface
{
    public function preflightPassed(CheckoutSession $session): void
    {
        // Production no-op
    }
}
