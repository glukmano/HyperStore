<?php

declare(strict_types=1);

namespace Modules\Promotions\Contracts;

use Modules\Promotions\DTOs\PromotionCartItem;

interface PromotionItemFilterActionInterface extends PromotionActionInterface
{
    /**
     * Determine if an individual cart item is specifically targeted or affected by this action.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function isItemTargeted(PromotionCartItem $item, array $parameters): bool;
}
