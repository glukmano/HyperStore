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

    public function canFulfillQuantity(int $requested): bool
    {
        return $this->readiness === self::READY
            && bccomp($this->available->toString(), (string) $requested, 4) >= 0;
    }
}
