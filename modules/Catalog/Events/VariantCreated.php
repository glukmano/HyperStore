<?php

declare(strict_types=1);

namespace Modules\Catalog\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Catalog\Models\ProductVariant;

class VariantCreated
{
    use Dispatchable;

    public function __construct(public ProductVariant $variant) {}
}
