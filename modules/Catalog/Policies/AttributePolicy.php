<?php

declare(strict_types=1);

namespace Modules\Catalog\Policies;

use App\Models\User;
use Modules\Catalog\Models\Attribute;

class AttributePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->hasPermissionTo('attributes.view') || $user->hasPermissionTo('catalog.view');
    }

    public function view(User $user, Attribute $attribute): bool
    {
        return $user->isSuperAdmin() || $user->hasPermissionTo('attributes.view') || $user->hasPermissionTo('catalog.view');
    }

    public function manage(User $user): bool
    {
        return $user->isSuperAdmin() || $user->hasPermissionTo('attributes.manage') || $user->hasPermissionTo('catalog.manage');
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Attribute $attribute): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, Attribute $attribute): bool
    {
        return $this->manage($user);
    }
}
