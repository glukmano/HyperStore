<?php

declare(strict_types=1);

namespace App\Core\Stores\Contracts;

use App\Core\Stores\Models\Store;

interface StoreCreationServiceInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createStore(int $tenantId, array $attributes): Store;
}
