<?php

declare(strict_types=1);

namespace Modules\Ledger\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Ledger\Contracts\JournalReversalServiceInterface;
use Modules\Ledger\Contracts\LedgerPostingServiceInterface;
use Modules\Ledger\DTOs\JournalDraftDTO;
use Modules\Ledger\DTOs\JournalLineDTO;
use Modules\Ledger\Enums\JournalDirection;
use Modules\Ledger\Exceptions\CrossTenantAccessException;
use Modules\Ledger\Exceptions\JournalAlreadyReversedException;
use Modules\Ledger\Models\JournalEntry;
use Modules\Ledger\Models\JournalLine;

class JournalReversalService implements JournalReversalServiceInterface
{
    public function __construct(
        private readonly LedgerPostingServiceInterface $postingService
    ) {}

    public function reverse(int $tenantId, string $journalUuid, string $reason): JournalEntry
    {
        return DB::transaction(function () use ($tenantId, $journalUuid, $reason): JournalEntry {
            /** @var JournalEntry|null $original */
            $original = JournalEntry::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('uuid', $journalUuid)
                ->with('lines')
                ->lockForUpdate()
                ->first();

            if ($original === null) {
                throw CrossTenantAccessException::forJournal($tenantId, $journalUuid);
            }

            if ($original->isReversed()) {
                throw JournalAlreadyReversedException::forJournal($journalUuid);
            }

            $now = CarbonImmutable::now('UTC');
            $inverseLines = [];

            foreach ($original->lines as $line) {
                /** @var JournalLine $line */
                $inverseDirection = $line->direction === JournalDirection::DEBIT->value
                    ? JournalDirection::CREDIT->value
                    : JournalDirection::DEBIT->value;

                $inverseLines[] = new JournalLineDTO(
                    accountId: (int) $line->ledger_account_id,
                    direction: $inverseDirection,
                    amountMinor: (int) $line->amount_minor,
                    currency: (string) $line->currency,
                    description: "Reversal line for line [{$line->uuid}]"
                );
            }

            $draft = new JournalDraftDTO(
                tenantId: $tenantId,
                sourceModule: 'ledger',
                sourceType: 'journal_entry',
                sourceUuid: $original->uuid,
                postingType: 'reversal',
                currency: $original->currency,
                description: "Reversal of journal [{$original->uuid}]: {$reason}",
                effectiveAt: $now,
                postedAt: $now,
                lines: $inverseLines,
                metadata: [
                    'reversed_journal_uuid' => $original->uuid,
                    'reason' => $reason,
                ],
                reversesJournalEntryId: $original->id
            );

            return $this->postingService->post($draft);
        });
    }
}
