<?php

declare(strict_types=1);

namespace Modules\Ledger\DTOs;

use Carbon\CarbonImmutable;

final readonly class JournalDraftDTO
{
    /**
     * @param  list<JournalLineDTO>  $lines
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public int $tenantId,
        public string $sourceModule,
        public string $sourceType,
        public string $sourceUuid,
        public string $postingType,
        public string $currency,
        public string $description,
        public CarbonImmutable $effectiveAt,
        public CarbonImmutable $postedAt,
        public array $lines,
        public ?array $metadata = null,
        public ?int $reversesJournalEntryId = null
    ) {}
}
