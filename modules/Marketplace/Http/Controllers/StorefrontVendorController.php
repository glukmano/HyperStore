<?php

declare(strict_types=1);

namespace Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Marketplace\Contracts\VendorStorefrontResolverInterface;

final class StorefrontVendorController extends Controller
{
    public function showByPath(string $slug, Request $request, VendorStorefrontResolverInterface $resolver): JsonResponse
    {
        $storeId = $request->has('store_id') ? (int) $request->query('store_id') : null;
        $resolved = $resolver->resolveByPath($slug, $storeId);

        return response()->json([
            'data' => [
                'vendor_uuid' => $resolved->vendor->uuid,
                'name' => $resolved->vendor->name,
                'platform_slug' => $resolved->vendor->platform_slug,
                'resolution_type' => $resolved->resolutionType,
                'canonical_url' => $resolved->canonicalUrl,
                'profile' => $resolved->profile,
            ],
        ]);
    }

    public function showBySubdomain(string $slug, Request $request, VendorStorefrontResolverInterface $resolver): JsonResponse
    {
        $storeId = $request->has('store_id') ? (int) $request->query('store_id') : null;
        $resolved = $resolver->resolveBySubdomain($slug, $storeId);

        return response()->json([
            'data' => [
                'vendor_uuid' => $resolved->vendor->uuid,
                'name' => $resolved->vendor->name,
                'platform_slug' => $resolved->vendor->platform_slug,
                'resolution_type' => $resolved->resolutionType,
                'canonical_url' => $resolved->canonicalUrl,
                'profile' => $resolved->profile,
            ],
        ]);
    }

    public function showByCustomDomain(Request $request, VendorStorefrontResolverInterface $resolver): JsonResponse
    {
        $host = (string) ($request->query('host') ?? $request->getHost());
        $storeId = $request->has('store_id') ? (int) $request->query('store_id') : null;

        $resolved = $resolver->resolveByCustomDomain($host, $storeId);

        return response()->json([
            'data' => [
                'vendor_uuid' => $resolved->vendor->uuid,
                'name' => $resolved->vendor->name,
                'platform_slug' => $resolved->vendor->platform_slug,
                'resolution_type' => $resolved->resolutionType,
                'canonical_url' => $resolved->canonicalUrl,
                'profile' => $resolved->profile,
            ],
        ]);
    }
}
