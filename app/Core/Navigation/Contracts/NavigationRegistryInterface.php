<?php

declare(strict_types=1);

namespace App\Core\Navigation\Contracts;

use App\Core\Navigation\DTOs\NavigationItem;
use App\Models\User;

/**
 * Single registration contract for Control Center sidebar entries.
 *
 * Today: Core and first-party Module ServiceProviders call register() from boot().
 * Future: the Plugin SDK registers through this exact same contract — no
 * Phase-15-only registration path exists that a Plugin would need to bypass.
 */
interface NavigationRegistryInterface
{
    public function register(NavigationItem $item): void;

    /**
     * @return list<NavigationItem>
     */
    public function all(): array;

    /**
     * Items visible to the given user in the given context, grouped by NavigationItem::$group,
     * ordered by NavigationItem::$order, filtered by Spatie permission and route existence.
     *
     * @return array<string, list<NavigationItem>>
     */
    public function visibleGrouped(?User $user, string $context): array;
}
