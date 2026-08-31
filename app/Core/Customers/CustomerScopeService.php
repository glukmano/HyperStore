<?php

declare(strict_types=1);

namespace App\Core\Customers;

use App\Core\Stores\Models\Store;
use App\Core\Stores\Models\StoreUser;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;

class CustomerScopeService
{
    /**
     * @return 'tenant_wide'|'store_isolated'
     */
    public function getEffectiveScope(Store $store): string
    {
        $override = $store->customer_account_scope_override;

        if ($override === 'store_isolated' || $override === 'tenant_wide') {
            return $override;
        }

        /** @var Tenant|null $tenant */
        $tenant = $store->tenant ?? Tenant::find($store->tenant_id);

        $scope = $tenant !== null ? $tenant->customer_account_scope : 'tenant_wide';

        return $scope === 'store_isolated' ? 'store_isolated' : 'tenant_wide';
    }

    public function hasCustomerAccess(User $user, Store $store): bool
    {
        $scope = $this->getEffectiveScope($store);

        if ($scope === 'tenant_wide') {
            return true;
        }

        return StoreUser::query()
            ->where('store_id', $store->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();
    }

    public function grantStoreCustomerAccess(User $user, Store $store): StoreUser
    {
        return StoreUser::updateOrCreate(
            [
                'store_id' => $store->id,
                'user_id' => $user->id,
            ],
            [
                'role' => 'customer',
                'is_active' => true,
            ]
        );
    }
}
