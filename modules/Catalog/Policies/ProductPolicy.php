<?php

declare(strict_types=1);

namespace Modules\Catalog\Policies;

use App\Models\User;
use Modules\Catalog\Models\Product;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->hasPermissionTo('products.view') || $user->hasPermissionTo('catalog.view');
    }

    public function view(User $user, Product $product): bool
    {
        return $user->isSuperAdmin() || $user->hasPermissionTo('products.view') || $user->hasPermissionTo('catalog.view');
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->hasPermissionTo('products.create') || $user->hasPermissionTo('catalog.manage');
    }

    public function update(User $user, Product $product): bool
    {
        return $user->isSuperAdmin() || $user->hasPermissionTo('products.update') || $user->hasPermissionTo('catalog.manage');
    }

    public function archive(User $user, Product $product): bool
    {
        return $user->isSuperAdmin() || $user->hasPermissionTo('products.archive') || $user->hasPermissionTo('catalog.manage');
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->archive($user, $product);
    }
}
