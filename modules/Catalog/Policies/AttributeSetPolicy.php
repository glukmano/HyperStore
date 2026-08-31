<?php

declare(strict_types=1);

namespace Modules\Catalog\Policies;

use App\Models\User;
use Modules\Catalog\Models\AttributeSet;

class AttributeSetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->hasPermissionTo('attribute_sets.view') || $user->hasPermissionTo('catalog.view');
    }

    public function view(User $user, AttributeSet $set): bool
    {
        return $user->isSuperAdmin() || $user->hasPermissionTo('attribute_sets.view') || $user->hasPermissionTo('catalog.view');
    }

    public function manage(User $user): bool
    {
        return $user->isSuperAdmin() || $user->hasPermissionTo('attribute_sets.manage') || $user->hasPermissionTo('catalog.manage');
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, AttributeSet $set): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, AttributeSet $set): bool
    {
        return $this->manage($user);
    }
}
