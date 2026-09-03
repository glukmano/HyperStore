<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use Carbon\CarbonImmutable;
use Modules\Marketplace\Enums\VendorDomainStatus;
use Modules\Marketplace\Exceptions\DomainAlreadyTakenException;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorDomain;
use Modules\Marketplace\ValueObjects\DomainName;

final class VendorDomainVerificationService
{
    public function registerDomain(Vendor $vendor, string $rawDomain): VendorDomain
    {
        $normalizedDomain = DomainName::from($rawDomain)->value();

        if (VendorDomain::withoutGlobalScopes()->where('domain', $normalizedDomain)->exists()) {
            throw DomainAlreadyTakenException::forDomain($normalizedDomain);
        }

        $token = bin2hex(random_bytes(16));

        /** @var VendorDomain $domain */
        $domain = VendorDomain::create([
            'tenant_id' => $vendor->tenant_id,
            'vendor_id' => $vendor->id,
            'domain' => $normalizedDomain,
            'verification_token' => $token,
            'status' => VendorDomainStatus::VerificationPending,
        ]);

        return $domain;
    }

    public function verifyDomain(VendorDomain $domain, ?string $simulatedTxtRecord = null): bool
    {
        $expectedChallenge = 'hyperstore-verification='.$domain->verification_token;

        $matched = false;

        if ($simulatedTxtRecord !== null) {
            $matched = ($simulatedTxtRecord === $expectedChallenge);
        } else {
            // DNS lookup
            if (function_exists('dns_get_record')) {
                $records = @dns_get_record($domain->domain, DNS_TXT);
                if (is_array($records)) {
                    foreach ($records as $record) {
                        if (isset($record['txt']) && $record['txt'] === $expectedChallenge) {
                            $matched = true;
                            break;
                        }
                    }
                }
            }
        }

        if ($matched) {
            $domain->status = VendorDomainStatus::Active;
            $domain->verified_at = CarbonImmutable::now();
            $domain->save();

            return true;
        }

        return false;
    }
}
