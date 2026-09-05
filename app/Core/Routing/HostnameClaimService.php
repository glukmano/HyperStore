<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Core\Routing\Exceptions\HostnameAlreadyClaimedException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase-18 Owner Delta §5: store_domains/market_domains/vendor_domains each
 * enforce their own per-table UNIQUE(domain) — which does NOT stop
 * "shop.example.com" being claimed once as a Store domain AND once as a
 * Market domain. This service is the one global arbiter: every domain
 * table's model fires a claim through here before its own row is created,
 * and the claim itself is protected by hostname_claims.normalized_hostname
 * UNIQUE — a real DB constraint, not a check-then-write race.
 */
final class HostnameClaimService
{
    public function claim(string $hostname, string $ownerType, int $ownerId): void
    {
        $normalized = HostnameNormalizer::normalize($hostname);

        try {
            DB::table('hostname_claims')->insert([
                'normalized_hostname' => $normalized,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw HostnameAlreadyClaimedException::forHost($normalized);
        }
    }

    public function release(string $hostname): void
    {
        DB::table('hostname_claims')
            ->where('normalized_hostname', HostnameNormalizer::normalize($hostname))
            ->delete();
    }
}
