<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use Carbon\CarbonImmutable;
use Modules\Marketplace\Contracts\DomainVerificationResolverInterface;
use Modules\Marketplace\Enums\VendorDomainStatus;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Exceptions\DomainAlreadyTakenException;
use Modules\Marketplace\Exceptions\VendorOperationalStatusException;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorDomain;
use Modules\Marketplace\ValueObjects\DomainName;

final class VendorDomainVerificationService
{
    public function __construct(
        private readonly DomainVerificationResolverInterface $resolver,
    ) {}

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

    public function verifyDomain(VendorDomain $domain): bool
    {
        $expectedChallenge = 'hyperstore-verification='.$domain->verification_token;

        $records = $this->resolver->resolveTxtRecords($domain->domain);
        $matched = false;

        foreach ($records as $record) {
            if ($record === $expectedChallenge) {
                $matched = true;
                break;
            }
        }

        if ($matched) {
            $domain->status = VendorDomainStatus::Verified;
            $domain->verified_at = CarbonImmutable::now();
            $domain->save();

            return true;
        }

        return false;
    }

    public function activateDomain(VendorDomain $domain): VendorDomain
    {
        if ($domain->status !== VendorDomainStatus::Verified) {
            throw new \DomainException("Cannot activate domain '{$domain->domain}' because it is not verified (status: {$domain->status->value}).");
        }

        $vendor = $domain->vendor;
        if ($vendor->operational_status !== VendorOperationalStatus::Active) {
            throw VendorOperationalStatusException::vendorNotActive($vendor->uuid, $vendor->operational_status->value);
        }

        $domain->status = VendorDomainStatus::Active;
        $domain->save();

        return $domain;
    }
}
