<?php

declare(strict_types=1);

namespace App\Core\Stores\Services;

use App\Core\Stores\Contracts\StoreCreationServiceInterface;
use App\Core\Stores\Models\Store;
use App\Core\SuperAdmin\Contracts\TenantResourceEntitlementGuardInterface;
use Illuminate\Support\Str;

final readonly class StoreCreationService implements StoreCreationServiceInterface
{
    public function __construct(
        private TenantResourceEntitlementGuardInterface $guard
    ) {}

    public function createStore(int $tenantId, array $attributes): Store
    {
        return $this->guard->admit($tenantId, 'max_stores', function () use ($tenantId, $attributes): Store {
            $slug = (string) ($attributes['slug'] ?? Str::slug((string) $attributes['name']));

            /** @var Store $store */
            $store = Store::create([
                'tenant_id' => $tenantId,
                'name' => (string) $attributes['name'],
                'slug' => $slug,
                'status' => (string) ($attributes['status'] ?? 'active'),
                'customer_account_scope_override' => (string) ($attributes['customer_account_scope_override'] ?? 'tenant_default'),
                'settings' => isset($attributes['settings']) && is_array($attributes['settings']) ? $attributes['settings'] : null,
            ]);

            return $store;
        });
    }
}
