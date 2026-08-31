<?php

declare(strict_types=1);

namespace Modules\Catalog\Policies;

use App\Models\User;
use Modules\Catalog\Models\Category;

class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->hasPermissionTo('categories.view') || $user->hasPermissionTo('catalog.view');
    }

    public function view(User $user, Category $category): bool
    {
        return $user->isSuperAdmin() || $user->hasPermissionTo('categories.view') || $user->hasPermissionTo('catalog.view');
    }

    public function manage(User $user): bool
    {
        return $user->isSuperAdmin() || $user->hasPermissionTo('categories.manage') || $user->hasPermissionTo('catalog.manage');
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Category $category): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->manage($user);
    }
}
