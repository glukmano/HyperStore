<?php

declare(strict_types=1);

namespace Modules\Checkout\Contracts;

use Modules\Cart\Models\Cart;

interface CheckoutPrerequisiteResolverInterface
{
    /**
     * @return array{
     *     requires_physical_shipping: bool,
     *     requires_inventory: bool,
     *     has_digital: bool,
     *     has_service: bool,
     *     has_physical: bool
     * }
     */
    public function resolveCartCapabilities(Cart $cart): array;
}
