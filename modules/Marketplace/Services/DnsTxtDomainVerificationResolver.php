<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use Modules\Marketplace\Contracts\DomainVerificationResolverInterface;

final class DnsTxtDomainVerificationResolver implements DomainVerificationResolverInterface
{
    public function resolveTxtRecords(string $domain): array
    {
        if (! function_exists('dns_get_record')) {
            return [];
        }

        $records = @dns_get_record($domain, DNS_TXT);
        if (! is_array($records)) {
            return [];
        }

        $txts = [];
        foreach ($records as $record) {
            if (isset($record['txt']) && is_string($record['txt'])) {
                $txts[] = $record['txt'];
            }
        }

        return $txts;
    }
}
