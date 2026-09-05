<?php

declare(strict_types=1);

namespace Modules\Affiliate\Http\Controllers;

use App\Core\Context\ContextManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Modules\Affiliate\Enums\AffiliateTargetType;
use Modules\Affiliate\Models\AffiliateClick;
use Modules\Affiliate\Models\AffiliateReferralCode;
use Modules\Affiliate\Services\AffiliateVisitorTokenService;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;
use Modules\Marketplace\Models\Vendor;

/**
 * GET /r/{code} — records the click, mints/refreshes the first-party
 * attribution cookie (Owner Delta correction §7), and redirects to the
 * referral code's target. An invalid/inactive/cross-Tenant code never 404s —
 * it safely falls back to the storefront home, same convention as every
 * other "safe fallback, never a raw 404" rule already established for
 * localized URL resolution (Phase-18).
 */
final class AffiliateClickTrackingController extends Controller
{
    public function __construct(
        private readonly AffiliateVisitorTokenService $tokenService,
    ) {}

    public function track(string $code): RedirectResponse
    {
        $rawTenantId = app(ContextManager::class)->getTenant()->getId();
        $tenantId = $rawTenantId !== null ? (int) $rawTenantId : null;

        $referralCode = $tenantId !== null
            ? AffiliateReferralCode::where('tenant_id', $tenantId)
                ->where('code', $code)
                ->where('is_active', true)
                ->first()
            : null;

        if ($referralCode === null) {
            return redirect()->route('storefront.home');
        }

        $tokenData = $this->tokenService->readOrMintHashedToken();

        AffiliateClick::create([
            'tenant_id' => $tenantId,
            'affiliate_referral_code_id' => $referralCode->id,
            'visitor_token_hash' => $tokenData['hash'],
            'landing_url' => request()->fullUrl(),
            'referer' => request()->header('referer'),
            'ip_hash' => request()->ip() !== null ? hash('sha256', (string) config('app.key').request()->ip()) : null,
            'user_agent' => request()->userAgent(),
            'clicked_at' => now(),
        ]);

        $response = redirect()->to($this->resolveTargetUrl($tenantId, $referralCode));

        if ($tokenData['is_new']) {
            $response->cookie(
                AffiliateVisitorTokenService::COOKIE_NAME,
                $tokenData['token'],
                AffiliateVisitorTokenService::COOKIE_LIFETIME_MINUTES,
                sameSite: 'lax'
            );
        }

        return $response;
    }

    private function resolveTargetUrl(int $tenantId, AffiliateReferralCode $referralCode): string
    {
        try {
            return match ($referralCode->target_type) {
                AffiliateTargetType::Platform, AffiliateTargetType::Store => route('storefront.home'),
                AffiliateTargetType::Vendor => $this->vendorUrl($tenantId, $referralCode->target_id) ?? route('storefront.home'),
                AffiliateTargetType::Category => $this->categoryUrl($tenantId, $referralCode->target_id) ?? route('storefront.home'),
                AffiliateTargetType::Product => $this->productUrl($tenantId, $referralCode->target_id) ?? route('storefront.home'),
            };
        } catch (\Throwable) {
            return route('storefront.home');
        }
    }

    private function vendorUrl(int $tenantId, ?int $targetId): ?string
    {
        if ($targetId === null) {
            return null;
        }
        $vendor = Vendor::where('tenant_id', $tenantId)->find($targetId);

        return $vendor !== null ? route('storefront.vendor', ['slug' => $vendor->platform_slug]) : null;
    }

    private function categoryUrl(int $tenantId, ?int $targetId): ?string
    {
        if ($targetId === null) {
            return null;
        }
        $category = Category::where('tenant_id', $tenantId)->find($targetId);

        return $category !== null ? route('storefront.category', ['code' => $category->code]) : null;
    }

    private function productUrl(int $tenantId, ?int $targetId): ?string
    {
        if ($targetId === null) {
            return null;
        }
        $product = Product::where('tenant_id', $tenantId)->find($targetId);

        return $product !== null ? route('storefront.product', ['sku' => $product->sku]) : null;
    }
}
