<?php

declare(strict_types=1);

namespace App\Livewire\Storefront\Concerns;

use App\Core\Context\ContextManager;
use Modules\Cart\ValueObjects\CartContext;

/**
 * The ContextManager -> CartContext construction shape already established
 * in ProductPage::addToCart() — shared here since Gift Registry "buy this",
 * Save-for-Later "move to cart", and other new actions need the identical
 * boilerplate.
 */
trait BuildsCartContext
{
    protected function buildCartContext(): ?CartContext
    {
        $context = app(ContextManager::class);
        $tenantId = $context->getTenant()->getId();
        $storeId = $context->getStore()->getId();

        if ($tenantId === null || $storeId === null) {
            return null;
        }

        return new CartContext(
            tenantId: (int) $tenantId,
            storeId: (int) $storeId,
            marketId: (int) ($context->getMarket()->getId() ?? 0),
            channelId: (int) ($context->getChannel()->getId() ?? 0),
            currency: $context->getCurrency()->getCode() ?? 'USD',
            locale: app()->getLocale(),
            userId: is_int(auth()->id()) ? auth()->id() : null,
            guestToken: session()->getId(),
        );
    }
}
