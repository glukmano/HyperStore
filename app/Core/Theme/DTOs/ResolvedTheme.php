<?php

declare(strict_types=1);

namespace App\Core\Theme\DTOs;

final readonly class ResolvedTheme
{
    /**
     * @param  list<string>  $chain  Theme names, most-specific first, ending in 'default'.
     * @param  list<string>  $viewPaths  Absolute view directories in the same order as $chain.
     */
    public function __construct(
        public string $activeThemeName,
        public array $chain,
        public array $viewPaths,
    ) {}
}
