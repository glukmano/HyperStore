<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use Tests\TestCase;

/**
 * Pre-Production Readiness (C.8): every registered NavigationItem icon must
 * be a real, self-hosted Lucide icon name (resources/icons/{name}.svg) —
 * never an emoji. Content-area emoji elsewhere in the app are untouched
 * and out of scope for this guard.
 */
class NavigationIconCleanupTest extends TestCase
{
    public function test_no_navigation_item_uses_an_emoji_icon(): void
    {
        /** @var NavigationRegistryInterface $registry */
        $registry = app(NavigationRegistryInterface::class);

        $offenders = [];
        foreach ($registry->all() as $item) {
            if ($item->icon !== null && preg_match('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}]/u', $item->icon)) {
                $offenders[] = "{$item->key}: {$item->icon}";
            }
        }

        $this->assertSame([], $offenders, 'Every NavigationItem icon must be a Lucide icon name, not an emoji.');
    }

    public function test_every_navigation_item_icon_resolves_to_a_real_svg_file(): void
    {
        /** @var NavigationRegistryInterface $registry */
        $registry = app(NavigationRegistryInterface::class);

        $missing = [];
        foreach ($registry->all() as $item) {
            if ($item->icon !== null && ! is_file(resource_path("icons/{$item->icon}.svg"))) {
                $missing[] = "{$item->key}: {$item->icon}";
            }
        }

        $this->assertSame([], $missing, 'Every NavigationItem icon name must have a matching resources/icons/*.svg file.');
    }
}
