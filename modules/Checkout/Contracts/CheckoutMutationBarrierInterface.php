<?php

declare(strict_types=1);

namespace Modules\Checkout\Contracts;

use Modules\Checkout\Models\CheckoutSession;

interface CheckoutMutationBarrierInterface
{
    /**
     * Invoked immediately after preflight check passes and before entering the mutation transaction.
     */
    public function preflightPassed(CheckoutSession $session): void;
}
