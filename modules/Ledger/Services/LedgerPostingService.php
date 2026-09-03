<?php

declare(strict_types=1);

namespace Modules\Ledger\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Ledger\Contracts\LedgerConcurrencyBarrierInterface;
use Modules\Ledger\Contracts\LedgerPostingServiceInterface;
use Modules\Ledger\DTOs\JournalDraftDTO;
use Modules\Ledger\DTOs\JournalLineDTO;
use Modules\Ledger\Enums\AccountStatus;
use Modules\Ledger\Enums\JournalDirection;
use Modules\Ledger\Exceptions\AccountCurrencyMismatchException;
use Modules\Ledger\Exceptions\CrossTenantAccessException;
use Modules\Ledger\Exceptions\InvalidJournalLineException;
use Modules\Ledger\Exceptions\JournalAlreadyReversedException;
use Modules\Ledger\Exceptions\UnbalancedJournalException;
use Modules\Ledger\Models\JournalEntry;
use Modules\Ledger\Models\JournalLine;
use Modules\Ledger\Models\LedgerAccount;

class LedgerPostingService implements LedgerPostingServiceInterface
{
    public function __construct(
        private readonly LedgerConcurrencyBarrierInterface $barrier
    ) {}

    public function post(JournalDraftDTO $draft): JournalEntry
    {
        // 1. Structural Line Count Validation
        if (count($draft->lines) < 2) {
            throw InvalidJournalLineException::forInsufficientLines();
        }

        // 2. Line Amount, Currency, and Balance Validation
        $debitTotal = 0;
        $creditTotal = 0;

        foreach ($draft->lines as $line) {
            if ($line->amountMinor <= 0) {
                throw InvalidJournalLineException::forNonPositiveAmount($line->amountMinor);
            }

            if ($line->currency !== $draft->currency) {
                throw InvalidJournalLineException::forCurrencyMismatch($line->currency, $draft->currency);
            }

            if ($line->directionValue === JournalDirection::DEBIT->value) {
                $debitTotal += $line->amountMinor;
            } elseif ($line->directionValue === JournalDirection::CREDIT->value) {
                $creditTotal += $line->amountMinor;
            } else {
                throw new InvalidJournalLineException("Invalid journal direction [{$line->directionValue}].");
            }
        }

        if ($debitTotal !== $creditTotal) {
            throw UnbalancedJournalException::withAmounts($debitTotal, $creditTotal, $draft->currency);
        }

        // 3. Execution inside a Database Transaction with Source Idempotency
        return DB::transaction(function () use ($draft): JournalEntry {
            // Check existing source idempotency first
            /** @var JournalEntry|null $existing */
            $existing = JournalEntry::withoutGlobalScopes()
                ->where('tenant_id', $draft->tenantId)
                ->where('source_module', $draft->sourceModule)
                ->where('source_type', $draft->sourceType)
                ->where('source_uuid', $draft->sourceUuid)
                ->where('posting_type', $draft->postingType)
                ->with('lines')
                ->first();

            if ($existing !== null) {
                if ($draft->postingType === 'reversal' || $draft->reversesJournalEntryId !== null) {
                    throw JournalAlreadyReversedException::forJournal((string) $draft->sourceUuid);
                }

                return $existing;
            }

            // 4. Validate Ledger Accounts
            $accountIds = array_unique(array_map(fn (JournalLineDTO $line) => $line->accountId, $draft->lines));
            $accounts = LedgerAccount::withoutGlobalScopes()
                ->where('tenant_id', $draft->tenantId)
                ->whereIn('id', $accountIds)
                ->get()
                ->keyBy('id');

            foreach ($draft->lines as $line) {
                /** @var LedgerAccount|null $account */
                $account = $accounts->get($line->accountId);

                if ($account === null) {
                    throw CrossTenantAccessException::forAccount($draft->tenantId, $line->accountId);
                }

                if ($account->status !== AccountStatus::ACTIVE->value) {
                    throw new CrossTenantAccessException("Ledger account [{$account->id}] is not active.");
                }

                if ($account->currency !== null && $account->currency !== $draft->currency) {
                    throw AccountCurrencyMismatchException::forAccount(
                        (int) $account->id,
                        (string) $account->currency,
                        $draft->currency
                    );
                }
            }

            // 5. Validate Reversal Target if Applicable
            if ($draft->reversesJournalEntryId !== null) {
                /** @var JournalEntry|null $originalEntry */
                $originalEntry = JournalEntry::withoutGlobalScopes()
                    ->where('tenant_id', $draft->tenantId)
                    ->where('id', $draft->reversesJournalEntryId)
                    ->lockForUpdate()
                    ->first();

                if ($originalEntry === null) {
                    throw CrossTenantAccessException::forJournal($draft->tenantId, (string) $draft->reversesJournalEntryId);
                }

                $alreadyReversed = JournalEntry::withoutGlobalScopes()
                    ->where('tenant_id', $draft->tenantId)
                    ->where('reverses_journal_entry_id', $originalEntry->id)
                    ->exists();

                if ($alreadyReversed) {
                    throw JournalAlreadyReversedException::forJournal($originalEntry->uuid);
                }
            }

            $this->barrier->wait('before_journal_entry_insert');

            // 6. Atomic Insertion with Savepoint to Gracefully Catch Race Insertions
            try {
                return DB::transaction(function () use ($draft): JournalEntry {
                    $now = CarbonImmutable::now('UTC');

                    /** @var JournalEntry $journal */
                    $journal = JournalEntry::create([
                        'uuid' => (string) Str::uuid(),
                        'tenant_id' => $draft->tenantId,
                        'source_module' => $draft->sourceModule,
                        'source_type' => $draft->sourceType,
                        'source_uuid' => $draft->sourceUuid,
                        'posting_type' => $draft->postingType,
                        'currency' => $draft->currency,
                        'reverses_journal_entry_id' => $draft->reversesJournalEntryId,
                        'description' => $draft->description,
                        'metadata' => $draft->metadata,
                        'effective_at' => $draft->effectiveAt,
                        'posted_at' => $draft->postedAt,
                        'created_at' => $now,
                    ]);

                    $this->barrier->wait('after_journal_entry_created');

                    foreach ($draft->lines as $line) {
                        JournalLine::create([
                            'uuid' => (string) Str::uuid(),
                            'tenant_id' => $draft->tenantId,
                            'journal_entry_id' => $journal->id,
                            'ledger_account_id' => $line->accountId,
                            'direction' => $line->directionValue,
                            'amount_minor' => $line->amountMinor,
                            'currency' => $line->currency,
                            'description' => $line->description,
                            'created_at' => $now,
                        ]);
                    }

                    return $journal->load('lines');
                });
            } catch (QueryException $e) {
                // If unique constraint on source posting was hit, retrieve the concurrently created journal
                if (str_contains($e->getMessage(), 'uq_journal_entries_source')) {
                    if ($draft->postingType === 'reversal' || $draft->reversesJournalEntryId !== null) {
                        throw JournalAlreadyReversedException::forJournal((string) $draft->sourceUuid);
                    }

                    /** @var JournalEntry $concurrentJournal */
                    $concurrentJournal = JournalEntry::withoutGlobalScopes()
                        ->where('tenant_id', $draft->tenantId)
                        ->where('source_module', $draft->sourceModule)
                        ->where('source_type', $draft->sourceType)
                        ->where('source_uuid', $draft->sourceUuid)
                        ->where('posting_type', $draft->postingType)
                        ->with('lines')
                        ->firstOrFail();

                    return $concurrentJournal;
                }

                if (str_contains($e->getMessage(), 'uq_journal_reversals')) {
                    throw JournalAlreadyReversedException::forJournal((string) $draft->sourceUuid);
                }

                throw $e;
            }
        });
    }
}
