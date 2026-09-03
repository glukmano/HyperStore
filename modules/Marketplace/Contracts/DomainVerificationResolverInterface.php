<?php

declare(strict_types=1);

namespace Modules\Marketplace\Contracts;

interface DomainVerificationResolverInterface
{
    /**
     * @return array<int, string>
     */
    public function resolveTxtRecords(string $domain): array;
}
