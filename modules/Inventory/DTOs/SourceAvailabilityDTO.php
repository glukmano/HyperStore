<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\ValueObjects\Quantity;

final readonly class SourceAvailabilityDTO
{
    public const string READY = 'ready';

    public const string PARTIAL = 'partial';

    public const string BACKORDERED = 'backordered';

    public const string PREORDER = 'preorder';

    public const string UNAVAILABLE = 'unavailable';

    public const string NON_PHYSICAL = 'non_physical';

    public function __construct(
        public int $sourceId,
        public Quantity $available,
        public Quantity $onHand,
        public Quantity $reserved,
        public string $readiness,
    ) {}

    public function isReady(): bool
    {
        return $this->readiness === self::READY;
    }

    public function canFulfillQuantity(int|string|Quantity $requested): bool
    {
        if ($this->readiness === self::BACKORDERED || $this->readiness === self::PREORDER) {
            return true;
        }

        /** @var numeric-string $reqStr */
        $reqStr = $requested instanceof Quantity ? $requested->toString() : (string) $requested;

        return $this->readiness === self::READY
            && bccomp($this->available->toString(), $reqStr, 4) >= 0;
    }
}
