<?php

declare(strict_types=1);

namespace Modules\Customers\Services;

use App\Core\Context\ContextManager;
use App\Models\User;
use Modules\Customers\Models\ProductFollow;
use Modules\Customers\Models\VendorFollow;

final class FollowService
{
    public function __construct(
        private readonly ContextManager $contextManager,
    ) {}

    public function followProduct(User $user, int $productId): ProductFollow
    {
        return ProductFollow::query()->firstOrCreate([
            'tenant_id' => (int) $this->contextManager->getTenant()->getId(),
            'user_id' => $user->id,
            'product_id' => $productId,
        ], ['created_at' => now()]);
    }

    public function unfollowProduct(User $user, int $productId): void
    {
        ProductFollow::query()
            ->where('tenant_id', (int) $this->contextManager->getTenant()->getId())
            ->where('user_id', $user->id)
            ->where('product_id', $productId)
            ->delete();
    }

    public function isFollowingProduct(User $user, int $productId): bool
    {
        return ProductFollow::query()
            ->where('tenant_id', (int) $this->contextManager->getTenant()->getId())
            ->where('user_id', $user->id)
            ->where('product_id', $productId)
            ->exists();
    }

    public function followVendor(User $user, int $vendorId): VendorFollow
    {
        return VendorFollow::query()->firstOrCreate([
            'tenant_id' => (int) $this->contextManager->getTenant()->getId(),
            'user_id' => $user->id,
            'vendor_id' => $vendorId,
        ], ['created_at' => now()]);
    }

    public function unfollowVendor(User $user, int $vendorId): void
    {
        VendorFollow::query()
            ->where('tenant_id', (int) $this->contextManager->getTenant()->getId())
            ->where('user_id', $user->id)
            ->where('vendor_id', $vendorId)
            ->delete();
    }

    public function isFollowingVendor(User $user, int $vendorId): bool
    {
        return VendorFollow::query()
            ->where('tenant_id', (int) $this->contextManager->getTenant()->getId())
            ->where('user_id', $user->id)
            ->where('vendor_id', $vendorId)
            ->exists();
    }
}
