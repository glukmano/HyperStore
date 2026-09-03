<?php

declare(strict_types=1);

namespace Modules\Ledger\Contracts;

use Modules\Ledger\Models\JournalEntry;

interface JournalReversalServiceInterface
{
    public function reverse(int $tenantId, string $journalUuid, string $reason): JournalEntry;
}
