<?php

declare(strict_types=1);

namespace Tests\Unit\Ledger;

use Carbon\CarbonImmutable;
use Modules\Ledger\DTOs\JournalDraftDTO;
use Modules\Ledger\DTOs\JournalLineDTO;
use Modules\Ledger\Enums\JournalDirection;
use Modules\Ledger\Exceptions\InvalidJournalLineException;
use Modules\Ledger\Exceptions\UnbalancedJournalException;
use Modules\Ledger\Services\LedgerPostingService;
use Modules\Ledger\Services\NoOpLedgerConcurrencyBarrier;
use PHPUnit\Framework\TestCase;

class JournalBalanceValidatorTest extends TestCase
{
    private LedgerPostingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LedgerPostingService(new NoOpLedgerConcurrencyBarrier);
    }

    public function test_rejects_insufficient_lines(): void
    {
        $draft = new JournalDraftDTO(
            tenantId: 1,
            sourceModule: 'test',
            sourceType: 'test',
            sourceUuid: 'uuid-1',
            postingType: 'capture',
            currency: 'EUR',
            description: 'Test',
            effectiveAt: CarbonImmutable::now(),
            postedAt: CarbonImmutable::now(),
            lines: [
                new JournalLineDTO(1, JournalDirection::DEBIT, 1000, 'EUR'),
            ]
        );

        $this->expectException(InvalidJournalLineException::class);
        $this->expectExceptionMessage('at least two journal lines');

        $this->service->post($draft);
    }

    public function test_rejects_non_positive_amount(): void
    {
        $draft = new JournalDraftDTO(
            tenantId: 1,
            sourceModule: 'test',
            sourceType: 'test',
            sourceUuid: 'uuid-1',
            postingType: 'capture',
            currency: 'EUR',
            description: 'Test',
            effectiveAt: CarbonImmutable::now(),
            postedAt: CarbonImmutable::now(),
            lines: [
                new JournalLineDTO(1, JournalDirection::DEBIT, 0, 'EUR'),
                new JournalLineDTO(2, JournalDirection::CREDIT, 0, 'EUR'),
            ]
        );

        $this->expectException(InvalidJournalLineException::class);
        $this->expectExceptionMessage('strictly positive');

        $this->service->post($draft);
    }

    public function test_rejects_line_currency_mismatch(): void
    {
        $draft = new JournalDraftDTO(
            tenantId: 1,
            sourceModule: 'test',
            sourceType: 'test',
            sourceUuid: 'uuid-1',
            postingType: 'capture',
            currency: 'EUR',
            description: 'Test',
            effectiveAt: CarbonImmutable::now(),
            postedAt: CarbonImmutable::now(),
            lines: [
                new JournalLineDTO(1, JournalDirection::DEBIT, 1000, 'EUR'),
                new JournalLineDTO(2, JournalDirection::CREDIT, 1000, 'USD'),
            ]
        );

        $this->expectException(InvalidJournalLineException::class);
        $this->expectExceptionMessage('does not match journal currency');

        $this->service->post($draft);
    }

    public function test_rejects_unbalanced_debit_and_credit_totals(): void
    {
        $draft = new JournalDraftDTO(
            tenantId: 1,
            sourceModule: 'test',
            sourceType: 'test',
            sourceUuid: 'uuid-1',
            postingType: 'capture',
            currency: 'EUR',
            description: 'Test',
            effectiveAt: CarbonImmutable::now(),
            postedAt: CarbonImmutable::now(),
            lines: [
                new JournalLineDTO(1, JournalDirection::DEBIT, 1000, 'EUR'),
                new JournalLineDTO(2, JournalDirection::CREDIT, 999, 'EUR'),
            ]
        );

        $this->expectException(UnbalancedJournalException::class);
        $this->expectExceptionMessage('debits [1000] !== credits [999]');

        $this->service->post($draft);
    }
}
