<?php

declare(strict_types=1);

namespace Modules\Promotions\Registries;

use InvalidArgumentException;
use Modules\Promotions\Contracts\PromotionConditionInterface;

class PromotionConditionRegistry
{
    /** @var array<string, PromotionConditionInterface> */
    private array $conditions = [];

    public function register(PromotionConditionInterface $condition): void
    {
        $type = $condition->getType();
        if (isset($this->conditions[$type])) {
            throw new InvalidArgumentException("Promotion condition [{$type}] is already registered.");
        }
        $this->conditions[$type] = $condition;
    }

    public function get(string $type): ?PromotionConditionInterface
    {
        return $this->conditions[$type] ?? null;
    }

    public function has(string $type): bool
    {
        return isset($this->conditions[$type]);
    }

    /**
     * @return array<string, PromotionConditionInterface>
     */
    public function all(): array
    {
        return $this->conditions;
    }
}
