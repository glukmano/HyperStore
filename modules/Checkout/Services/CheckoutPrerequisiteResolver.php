<?php

declare(strict_types=1);

namespace Modules\Checkout\Services;

use Modules\Cart\Models\Cart;
use Modules\Cart\Models\CartLine;
use Modules\Catalog\Contracts\ProductShippingCapabilityResolverInterface;
use Modules\Catalog\Contracts\ProductTypeRegistryInterface;
use Modules\Checkout\Contracts\CheckoutPrerequisiteResolverInterface;

class CheckoutPrerequisiteResolver implements CheckoutPrerequisiteResolverInterface
{
    public function __construct(
        private readonly ProductShippingCapabilityResolverInterface $shippingCapabilityResolver,
        private readonly ProductTypeRegistryInterface $productTypeRegistry
    ) {}

    public function resolveCartCapabilities(Cart $cart): array
    {
        $requiresShipping = false;
        $requiresInventory = false;
        $hasDigital = false;
        $hasService = false;
        $hasPhysical = false;

        $cart->loadMissing('lines.product');

        foreach ($cart->lines as $line) {
            /** @var CartLine $line */
            $product = $line->product;

            $needsShipping = $this->shippingCapabilityResolver->requiresPhysicalShipping($product);
            $needsInv = $this->shippingCapabilityResolver->supportsInventory($product);

            if ($needsShipping) {
                $requiresShipping = true;
                $hasPhysical = true;
            } else {
                $hasDigital = true;
            }

            if ($needsInv) {
                $requiresInventory = true;
            }

            if ($this->productTypeRegistry->has((string) $product->product_type)) {
                $type = $this->productTypeRegistry->get((string) $product->product_type);
                if ($type->supportsBooking()) {
                    $hasService = true;
                }
            }
        }

        return [
            'requires_physical_shipping' => $requiresShipping,
            'requires_inventory' => $requiresInventory,
            'has_digital' => $hasDigital,
            'has_service' => $hasService,
            'has_physical' => $hasPhysical,
        ];
    }
}
