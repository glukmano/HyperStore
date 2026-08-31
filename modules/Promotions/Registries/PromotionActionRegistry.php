<?php

declare(strict_types=1);

namespace Modules\Promotions\Registries;

use InvalidArgumentException;
use Modules\Promotions\Contracts\PromotionActionInterface;

class PromotionActionRegistry
{
    /** @var array<string, PromotionActionInterface> */
    private array $actions = [];

    public function register(PromotionActionInterface $action): void
    {
        $type = $action->getType();
        if (isset($this->actions[$type])) {
            throw new InvalidArgumentException("Promotion action [{$type}] is already registered.");
        }
        $this->actions[$type] = $action;
    }

    public function get(string $type): ?PromotionActionInterface
    {
        return $this->actions[$type] ?? null;
    }

    public function has(string $type): bool
    {
        return isset($this->actions[$type]);
    }

    /**
     * @return array<string, PromotionActionInterface>
     */
    public function all(): array
    {
        return $this->actions;
    }
}
