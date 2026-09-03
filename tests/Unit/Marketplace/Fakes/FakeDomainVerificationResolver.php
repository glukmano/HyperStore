<?php

declare(strict_types=1);

namespace Tests\Unit\Marketplace\Fakes;

use Modules\Marketplace\Contracts\DomainVerificationResolverInterface;

final class FakeDomainVerificationResolver implements DomainVerificationResolverInterface
{
    /** @var array<string, array<int, string>> */
    private array $records = [];

    public function setTxtRecords(string $domain, array $records): void
    {
        $this->records[$domain] = $records;
    }

    public function addTxtRecord(string $domain, string $record): void
    {
        $this->records[$domain][] = $record;
    }

    public function resolveTxtRecords(string $domain): array
    {
        return $this->records[$domain] ?? [];
    }
}
