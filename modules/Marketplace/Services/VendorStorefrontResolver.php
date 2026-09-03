<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use App\Core\Stores\Models\Store;
use Modules\Marketplace\Contracts\VendorStorefrontResolverInterface;
use Modules\Marketplace\DTOs\ResolvedStorefrontDTO;
use Modules\Marketplace\Enums\VendorDomainStatus;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Exceptions\VendorNotFoundException;
use Modules\Marketplace\Exceptions\VendorOperationalStatusException;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorDomain;
use Modules\Marketplace\Models\VendorStoreParticipation;
use Modules\Marketplace\ValueObjects\DomainName;
use Modules\Marketplace\ValueObjects\VendorSlug;

final class VendorStorefrontResolver implements VendorStorefrontResolverInterface
{
    public function resolveByPath(string $vendorSlug, ?int $storeId = null): ResolvedStorefrontDTO
    {
        $normalizedSlug = VendorSlug::from($vendorSlug)->value();

        /** @var Vendor|null $vendor */
        $vendor = Vendor::withoutGlobalScopes()
            ->with(['storefrontProfile', 'plan'])
            ->where('platform_slug', $normalizedSlug)
            ->first();

        if ($vendor === null) {
            throw VendorNotFoundException::forSlug($normalizedSlug);
        }

        $this->assertVendorActive($vendor);

        $store = $this->resolveStore($vendor, $storeId);

        return new ResolvedStorefrontDTO(
            vendor: $vendor,
            profile: $vendor->storefrontProfile,
            store: $store,
            resolutionType: 'path',
            canonicalUrl: "/{$normalizedSlug}",
        );
    }

    public function resolveBySubdomain(string $vendorSlug, ?int $storeId = null): ResolvedStorefrontDTO
    {
        $normalizedSlug = VendorSlug::from($vendorSlug)->value();

        /** @var Vendor|null $vendor */
        $vendor = Vendor::withoutGlobalScopes()
            ->with(['storefrontProfile', 'plan'])
            ->where('platform_slug', $normalizedSlug)
            ->first();

        if ($vendor === null) {
            throw VendorNotFoundException::forSlug($normalizedSlug);
        }

        $this->assertVendorActive($vendor);

        $store = $this->resolveStore($vendor, $storeId);

        return new ResolvedStorefrontDTO(
            vendor: $vendor,
            profile: $vendor->storefrontProfile,
            store: $store,
            resolutionType: 'subdomain',
            canonicalUrl: "https://{$normalizedSlug}.".config('app.url', 'localhost'),
        );
    }

    public function resolveByCustomDomain(string $domain, ?int $storeId = null): ResolvedStorefrontDTO
    {
        $normalizedDomain = DomainName::from($domain)->value();

        /** @var VendorDomain|null $vendorDomain */
        $vendorDomain = VendorDomain::withoutGlobalScopes()
            ->with(['vendor.storefrontProfile', 'vendor.plan'])
            ->where('domain', $normalizedDomain)
            ->first();

        if ($vendorDomain === null) {
            throw new VendorNotFoundException("No vendor registered with custom domain '{$normalizedDomain}'.");
        }

        if ($vendorDomain->status !== VendorDomainStatus::Active) {
            throw new VendorNotFoundException("Custom domain '{$normalizedDomain}' is not active (status: {$vendorDomain->status->value}).");
        }

        $vendor = $vendorDomain->vendor;
        $this->assertVendorActive($vendor);

        $store = $this->resolveStore($vendor, $storeId);

        return new ResolvedStorefrontDTO(
            vendor: $vendor,
            profile: $vendor->storefrontProfile,
            store: $store,
            resolutionType: 'custom_domain',
            canonicalUrl: "https://{$normalizedDomain}",
        );
    }

    private function assertVendorActive(Vendor $vendor): void
    {
        if ($vendor->operational_status !== VendorOperationalStatus::Active) {
            throw VendorOperationalStatusException::vendorNotActive($vendor->uuid, $vendor->operational_status->value);
        }
    }

    private function resolveStore(Vendor $vendor, ?int $storeId): ?Store
    {
        $targetStoreId = $storeId ?? $vendor->default_store_id;
        if ($targetStoreId === null) {
            return null;
        }

        /** @var Store|null $store */
        $store = Store::find($targetStoreId);
        if ($store === null) {
            throw new VendorNotFoundException("Store {$targetStoreId} does not exist.");
        }

        // Must belong to the exact same tenant
        if ($store->tenant_id !== $vendor->tenant_id) {
            throw new VendorNotFoundException("Store {$targetStoreId} belongs to a foreign tenant.");
        }

        // Must actively participate in the store
        $participating = VendorStoreParticipation::where('tenant_id', $vendor->tenant_id)
            ->where('vendor_id', $vendor->id)
            ->where('store_id', $targetStoreId)
            ->where('is_enabled', true)
            ->exists();

        if (! $participating) {
            throw new VendorNotFoundException("Vendor {$vendor->id} does not participate in store {$targetStoreId}.");
        }

        return $store;
    }
}
