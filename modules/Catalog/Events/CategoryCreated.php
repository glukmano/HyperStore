<?php

declare(strict_types=1);

namespace Modules\Catalog\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Catalog\Models\Category;

class CategoryCreated
{
    use Dispatchable;

    public function __construct(public Category $category) {}
}
