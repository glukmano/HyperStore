<?php

declare(strict_types=1);

namespace Modules\Checkout\Exceptions;

use RuntimeException;

class TaxClassUnavailableException extends RuntimeException
{
    public static function forProduct(int $productId): self
    {
        return new self("TAX_CLASS_UNAVAILABLE: Product [{$productId}] has no tax class assigned and no tenant default tax class exists.", 422);
    }
}
