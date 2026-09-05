<?php

declare(strict_types=1);

namespace App\Core\Routing\DTOs;

use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;

/**
 * One exact host resolves an unambiguous Store (+ Market, when the host is
 * a regional Market domain) — never a guessed default (Phase-18 Owner
 * Delta §4).
 */
final readonly class ResolvedHostContext
{
    public function __construct(
        public ?Store $store,
        public ?Market $market,
    ) {}

    public static function empty(): self
    {
        return new self(null, null);
    }
}
