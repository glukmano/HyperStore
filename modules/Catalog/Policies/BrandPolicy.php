<?php

declare(strict_types=1);

namespace Modules\Catalog\Policies;

use App\Models\User;
use Modules\Catalog\Models\Brand;

class BrandPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->hasPermissionTo('brands.view') || $user->hasPermissionTo('catalog.view');
    }

    public function view(User $user, Brand $brand): bool
    {
        return $user->isSuperAdmin() || $user->hasPermissionTo('brands.view') || $user->hasPermissionTo('catalog.view');
    }

    public function manage(User $user): bool
    {
        return $user->isSuperAdmin() || $user->hasPermissionTo('brands.manage') || $user->hasPermissionTo('catalog.manage');
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Brand $brand): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $this->manage($user);
    }
}
