<?php

declare(strict_types=1);

namespace App\Core\Navigation;

use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use App\Core\Navigation\DTOs\NavigationItem;
use App\Models\User;
use Illuminate\Support\Facades\Route;

final class NavigationRegistry implements NavigationRegistryInterface
{
    /** @var list<NavigationItem> */
    private array $items = [];

    public function register(NavigationItem $item): void
    {
        $this->items[] = $item;
    }

    public function all(): array
    {
        return $this->items;
    }

    public function visibleGrouped(?User $user, string $context): array
    {
        $visible = array_filter(
            $this->items,
            function (NavigationItem $item) use ($user, $context): bool {
                if ($item->context !== $context && $item->context !== 'all') {
                    return false;
                }

                if (! Route::has($item->routeName)) {
                    return false;
                }

                if ($item->permission === null) {
                    return true;
                }

                if ($user === null) {
                    return false;
                }

                if ($user->isSuperAdmin()) {
                    return true;
                }

                return $user->can($item->permission);
            }
        );

        $grouped = [];
        foreach ($visible as $item) {
            $grouped[$item->group][] = $item;
        }

        foreach ($grouped as $group => $groupItems) {
            usort($groupItems, fn (NavigationItem $a, NavigationItem $b) => $a->order <=> $b->order);
            $grouped[$group] = $groupItems;
        }

        return $grouped;
    }
}
