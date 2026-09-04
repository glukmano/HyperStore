<?php

declare(strict_types=1);

namespace Modules\Customers\Contracts;

use App\Models\User;
use Modules\Customers\Models\SavedForLaterItem;

/**
 * The one explicit two-way seam between Cart and Customers (approved plan
 * §8): Cart calls this to park a line as "saved for later" without reaching
 * into Customers' tables directly. The reverse direction (moving a saved
 * item back into an active cart) goes through Cart's own, pre-existing
 * CartServiceInterface — Customers never writes to Cart's tables either.
 */
interface SaveForLaterServiceInterface
{
    public function saveForLater(User $user, int $productId, ?int $variantId, int $quantity, int $unitPriceMinorSnapshot): SavedForLaterItem;
}
