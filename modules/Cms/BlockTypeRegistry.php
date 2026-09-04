<?php

declare(strict_types=1);

namespace Modules\Cms;

use Modules\Cms\Contracts\BlockTypeRegistryInterface;
use Modules\Cms\DTOs\BlockTypeDefinition;

final class BlockTypeRegistry implements BlockTypeRegistryInterface
{
    /**
     * @var array<string, BlockTypeDefinition>
     */
    private array $definitions = [];

    public function register(BlockTypeDefinition $definition): void
    {
        $this->definitions[$definition->key] = $definition;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    public function get(string $key): ?BlockTypeDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    /**
     * @return array<string, BlockTypeDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }
}
