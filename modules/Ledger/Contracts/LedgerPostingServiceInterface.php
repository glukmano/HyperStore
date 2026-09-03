<?php

declare(strict_types=1);

namespace Modules\Ledger\Contracts;

use Modules\Ledger\DTOs\JournalDraftDTO;
use Modules\Ledger\Models\JournalEntry;

interface LedgerPostingServiceInterface
{
    public function post(JournalDraftDTO $draft): JournalEntry;
}
