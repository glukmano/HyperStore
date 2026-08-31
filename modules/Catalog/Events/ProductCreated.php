<?php

declare(strict_types=1);

namespace Modules\Catalog\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Catalog\Models\Product;

class ProductCreated
{
    use Dispatchable;

    public function __construct(public Product $product) {}
}
