<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use DateTimeImmutable;
use Modules\Inventory\ValueObjects\Quantity;

/**
 * Read-only external stock availability snapshot (ADR-0124). Consumed live, at
 * read time, by InventorySourceQueryService — never persisted into
 * stock_items.on_hand. `available === null` and `unavailable === true` together
 * represent a fail-closed resolution failure or timeout (never assumed-available).
 */
final readonly class ExternalStockSnapshotDTO
{
    public function __construct(
        public ?Quantity $available,
        public bool $unavailable,
        public ?DateTimeImmutable $asOf = null,
    ) {}

    public static function unavailable(): self
    {
        return new self(available: null, unavailable: true, asOf: null);
    }

    public static function of(Quantity $available, ?DateTimeImmutable $asOf = null): self
    {
        return new self(available: $available, unavailable: false, asOf: $asOf);
    }
}
