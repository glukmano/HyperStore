<?php

declare(strict_types=1);

namespace Modules\Checkout\Services;

use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\Models\Cart;
use Modules\Cart\Models\CartLine;
use Modules\Catalog\Contracts\ProductTypeRegistryInterface;
use Modules\Catalog\Models\ProductTranslation;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\DTOs\CheckoutAddress;
use Modules\Checkout\DTOs\CheckoutCustomerData;
use Modules\Checkout\DTOs\CheckoutReadyResult;
use Modules\Checkout\DTOs\SelectedShippingQuote;
use Modules\Checkout\Exceptions\CheckoutExpiredException;
use Modules\Checkout\Exceptions\ShippingQuoteExpiredException;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Marketplace\Contracts\MarketplaceCommercialPolicyInterface;
use Modules\Marketplace\Contracts\MarketplaceConcurrencyBarrierInterface;
use Modules\Marketplace\Contracts\VendorCommissionQuoteServiceInterface;
use Modules\Marketplace\Contracts\VendorListingResolutionServiceInterface;
use RuntimeException;

class CheckoutOrchestrator implements CheckoutOrchestratorInterface
{
    public function __construct(
        private readonly CheckoutPricingOrchestrator $pricingOrchestrator,
        private readonly CheckoutShippingOrchestrator $shippingOrchestrator,
        private readonly CheckoutInventoryReservationOrchestrator $reservationOrchestrator,
        private readonly CheckoutIdempotencyService $idempotencyService,
        private readonly CheckoutStateMachineService $stateMachine,
        private readonly CartServiceInterface $cartService,
        private readonly CheckoutExpirationService $expirationService,
        private readonly TenantLicenseServiceInterface $licenseService
    ) {}

    public function createFromCart(Cart $cart, ?string $idempotencyKey = null): CheckoutSession
    {
        $payload = [
            'cart_id' => $cart->id,
            'cart_version' => $cart->version,
            'currency' => $cart->currency,
        ];

        $findExisting = function () use ($cart): ?CheckoutSession {
            return CheckoutSession::query()
                ->where('tenant_id', $cart->tenant_id)
                ->where('cart_id', $cart->id)
                ->whereNotIn('state', ['ready_for_order', 'expired', 'cancelled', 'failed'])
                ->first();
        };

        $createSession = function () use ($cart, $findExisting): array {
            if ($cart->lines()->count() === 0) {
                throw new RuntimeException('Cannot create CheckoutSession from empty Cart.');
            }

            // Authoritative license check: missing, suspended, or expired fails closed
            $this->licenseService->assertActiveForTenant($cart->tenant_id);

            // In PostgreSQL, use advisory xact lock per (tenant, cart) to cleanly serialize concurrent creation
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(?, ?)', [$cart->tenant_id, $cart->id]);
            } else {
                Cart::query()->where('id', $cart->id)->lockForUpdate()->first();
            }

            $existing = $findExisting();
            if ($existing !== null) {
                return [
                    'session_id' => $existing->id,
                    'uuid' => $existing->uuid,
                ];
            }

            $session = CheckoutSession::create([
                'tenant_id' => $cart->tenant_id,
                'cart_id' => $cart->id,
                'user_id' => $cart->user_id,
                'guest_token_hash' => $cart->guest_token_hash,
                'store_id' => $cart->store_id,
                'market_id' => $cart->market_id,
                'channel_id' => $cart->channel_id,
                'currency' => $cart->currency,
                'state' => 'created',
                'evaluated_cart_version' => $cart->version,
                'expires_at' => now()->addMinutes(60),
            ]);

            return [
                'session_id' => $session->id,
                'uuid' => $session->uuid,
            ];
        };

        $res = $this->idempotencyService->execute(
            tenantId: $cart->tenant_id,
            cartId: $cart->id,
            checkoutSessionId: null,
            operationType: 'create_checkout',
            idempotencyKey: $idempotencyKey,
            requestPayload: $payload,
            callback: $createSession
        );

        /** @var CheckoutSession $session */
        $session = CheckoutSession::query()->where('id', $res['session_id'])->firstOrFail();

        return $session;
    }

    public function setCustomerData(CheckoutSession $session, CheckoutCustomerData $customerData, ?string $idempotencyKey = null): CheckoutSession
    {
        $this->assertFreshCart($session);

        $payload = $customerData->toArray();

        $this->idempotencyService->execute(
            tenantId: $session->tenant_id,
            cartId: null,
            checkoutSessionId: $session->id,
            operationType: 'customer_data',
            idempotencyKey: $idempotencyKey,
            requestPayload: $payload,
            callback: function () use ($session, $customerData) {
                /** @var CheckoutSession $lockedSession */
                $lockedSession = CheckoutSession::query()->where('id', $session->id)->lockForUpdate()->firstOrFail();
                $this->assertFreshCart($lockedSession);

                $this->stateMachine->assertCanTransition($lockedSession, 'customer_info_ready');

                $lockedSession->customer_data = $customerData->toArray();
                $lockedSession->state = 'customer_info_ready';
                $lockedSession->version++;
                $lockedSession->save();

                return ['session_id' => $session->id];
            }
        );

        $session->refresh();

        return $session;
    }

    public function setAddresses(CheckoutSession $session, CheckoutAddress $shippingAddress, ?CheckoutAddress $billingAddress = null, ?string $idempotencyKey = null): CheckoutSession
    {
        $this->assertFreshCart($session);

        $payload = [
            'shipping' => $shippingAddress->toArray(),
            'billing' => $billingAddress?->toArray(),
        ];

        $this->idempotencyService->execute(
            tenantId: $session->tenant_id,
            cartId: null,
            checkoutSessionId: $session->id,
            operationType: 'addresses',
            idempotencyKey: $idempotencyKey,
            requestPayload: $payload,
            callback: function () use ($session, $shippingAddress, $billingAddress) {
                /** @var CheckoutSession $lockedSession */
                $lockedSession = CheckoutSession::query()->where('id', $session->id)->lockForUpdate()->firstOrFail();
                $this->assertFreshCart($lockedSession);

                if (in_array($lockedSession->state, ['created', 'customer_info_ready'], true)) {
                    $this->stateMachine->assertCanTransition($lockedSession, 'address_ready');
                    $lockedSession->state = 'address_ready';
                }

                $lockedSession->shipping_address = $shippingAddress->toArray();
                $lockedSession->billing_address = $billingAddress?->toArray();

                // Address change invalidates previous shipping selection and recalculates tax
                $quoteRes = $this->shippingOrchestrator->quote($lockedSession->cart, $shippingAddress);
                $pricingRes = $this->pricingOrchestrator->calculate($lockedSession->cart, $shippingAddress, null);

                $lockedSession->pricing_snapshot = $pricingRes['pricing_snapshot'];
                $lockedSession->tax_snapshot = $pricingRes['tax_snapshot'];
                $lockedSession->promotion_snapshot = $pricingRes['promotion_snapshot'];
                $lockedSession->fulfillment_snapshot = [
                    'groups_count' => count($quoteRes['fulfillment_plan']->groups),
                    'has_splits' => $quoteRes['fulfillment_plan']->hasSplits,
                ];
                $lockedSession->selected_shipping_quote = null;

                $lockedSession->version++;
                $lockedSession->save();

                return ['session_id' => $session->id];
            }
        );

        $session->refresh();

        return $session;
    }

    public function selectShippingQuote(CheckoutSession $session, array $rateQuoteData, ?string $idempotencyKey = null): CheckoutSession
    {
        $this->assertFreshCart($session);

        $payload = [
            'method_id' => (int) $rateQuoteData['method_id'],
            'method_code' => (string) $rateQuoteData['method_code'],
            'carrier_code' => $rateQuoteData['carrier_code'] ?? null,
            'service_code' => $rateQuoteData['service_code'] ?? null,
        ];

        $this->idempotencyService->execute(
            tenantId: $session->tenant_id,
            cartId: null,
            checkoutSessionId: $session->id,
            operationType: 'shipping_selection',
            idempotencyKey: $idempotencyKey,
            requestPayload: $payload,
            callback: function () use ($session, $rateQuoteData) {
                /** @var CheckoutSession $lockedSession */
                $lockedSession = CheckoutSession::query()->where('id', $session->id)->lockForUpdate()->firstOrFail();
                $this->assertFreshCart($lockedSession);

                if ($lockedSession->shipping_address === null) {
                    throw new RuntimeException('Cannot select shipping quote without shipping address.');
                }

                $dest = CheckoutAddress::fromArray($lockedSession->shipping_address);

                // Derive authoritative SelectedShippingQuote (never trust client amounts)
                $authoritativeQuote = $this->shippingOrchestrator->buildAuthoritativeSelectedQuote(
                    $lockedSession->cart,
                    $dest,
                    $rateQuoteData
                );

                $lockedSession->selected_shipping_quote = $authoritativeQuote->toArray();

                $pricingRes = $this->pricingOrchestrator->calculate($lockedSession->cart, $dest, $authoritativeQuote);
                $lockedSession->pricing_snapshot = $pricingRes['pricing_snapshot'];
                $lockedSession->tax_snapshot = $pricingRes['tax_snapshot'];
                $lockedSession->promotion_snapshot = $pricingRes['promotion_snapshot'];

                if (in_array($lockedSession->state, ['address_ready', 'customer_info_ready', 'fulfillment_ready'], true)) {
                    $this->stateMachine->assertCanTransition($lockedSession, 'shipping_ready');
                    $lockedSession->state = 'shipping_ready';
                }

                $lockedSession->version++;
                $lockedSession->save();

                return ['session_id' => $session->id];
            }
        );

        $session->refresh();

        return $session;
    }

    public function reserveInventory(CheckoutSession $session, ?string $idempotencyKey = null): CheckoutSession
    {
        $this->expirationService->expireIfNeeded($session);
        $this->assertFreshCart($session);

        $payload = [
            'checkout_session_id' => $session->id,
            'version' => $session->version,
        ];

        try {
            $this->idempotencyService->execute(
                tenantId: $session->tenant_id,
                cartId: null,
                checkoutSessionId: $session->id,
                operationType: 'reserve',
                idempotencyKey: $idempotencyKey,
                requestPayload: $payload,
                callback: function () use ($session) {
                    /** @var CheckoutSession $lockedSession */
                    $lockedSession = CheckoutSession::query()->where('id', $session->id)->lockForUpdate()->firstOrFail();
                    if ($lockedSession->expires_at->isPast()) {
                        throw new CheckoutExpiredException("CHECKOUT_EXPIRED: Checkout session [{$lockedSession->id}] has expired at [{$lockedSession->expires_at->toIso8601String()}].");
                    }
                    $this->assertFreshCart($lockedSession);

                    $this->assertValidShippingQuote($lockedSession);

                    $dest = $lockedSession->shipping_address !== null
                        ? CheckoutAddress::fromArray($lockedSession->shipping_address)
                        : new CheckoutAddress(recipient: 'Customer', streetLines: ['Main St'], city: 'Zurich', countryCode: 'CH');

                    $quoteRes = $this->shippingOrchestrator->quote($lockedSession->cart, $dest);
                    $plan = $quoteRes['fulfillment_plan'];

                    $acquiredRefs = $this->reservationOrchestrator->reserve($lockedSession, $plan);

                    $lockedSession->reservation_references = $acquiredRefs;

                    if (in_array($lockedSession->state, ['shipping_ready', 'fulfillment_ready', 'address_ready', 'customer_info_ready'], true)) {
                        $this->stateMachine->assertCanTransition($lockedSession, 'inventory_reserved');
                        $lockedSession->state = 'inventory_reserved';
                    }

                    $lockedSession->version++;
                    $lockedSession->save();

                    return ['session_id' => $session->id];
                }
            );
        } catch (CheckoutExpiredException $e) {
            $this->expirationService->expireIfNeeded($session);
            throw $e;
        }

        $session->refresh();

        return $session;
    }

    public function recalculate(CheckoutSession $session, ?string $idempotencyKey = null): CheckoutSession
    {
        $this->expirationService->expireIfNeeded($session);
        $this->assertFreshCart($session);

        $payload = [
            'checkout_session_id' => $session->id,
            'version' => $session->version,
        ];

        try {
            $this->idempotencyService->execute(
                tenantId: $session->tenant_id,
                cartId: null,
                checkoutSessionId: $session->id,
                operationType: 'recalculate',
                idempotencyKey: $idempotencyKey,
                requestPayload: $payload,
                callback: function () use ($session) {
                    /** @var CheckoutSession $lockedSession */
                    $lockedSession = CheckoutSession::query()->where('id', $session->id)->lockForUpdate()->firstOrFail();
                    if ($lockedSession->expires_at->isPast()) {
                        throw new CheckoutExpiredException("CHECKOUT_EXPIRED: Checkout session [{$lockedSession->id}] has expired at [{$lockedSession->expires_at->toIso8601String()}].");
                    }
                    $this->assertFreshCart($lockedSession);

                    $this->assertValidShippingQuote($lockedSession);

                    $dest = $lockedSession->shipping_address !== null ? CheckoutAddress::fromArray($lockedSession->shipping_address) : null;
                    $quote = $lockedSession->selected_shipping_quote !== null ? SelectedShippingQuote::fromArray($lockedSession->selected_shipping_quote, $lockedSession->currency) : null;

                    $pricingRes = $this->pricingOrchestrator->calculate($lockedSession->cart, $dest, $quote);

                    $lockedSession->pricing_snapshot = $pricingRes['pricing_snapshot'];
                    $lockedSession->tax_snapshot = $pricingRes['tax_snapshot'];
                    $lockedSession->promotion_snapshot = $pricingRes['promotion_snapshot'];

                    $lockedSession->version++;
                    $lockedSession->save();

                    return ['session_id' => $session->id];
                }
            );
        } catch (CheckoutExpiredException $e) {
            $this->expirationService->expireIfNeeded($session);
            throw $e;
        }

        $session->refresh();

        return $session;
    }

    public function applyCoupon(CheckoutSession $session, string $couponCode, ?string $idempotencyKey = null): CheckoutSession
    {
        $this->assertFreshCart($session);

        $payload = [
            'coupon_code' => $couponCode,
        ];

        $this->idempotencyService->execute(
            tenantId: $session->tenant_id,
            cartId: null,
            checkoutSessionId: $session->id,
            operationType: 'apply_coupon',
            idempotencyKey: $idempotencyKey,
            requestPayload: $payload,
            callback: function () use ($session, $couponCode) {
                /** @var CheckoutSession $lockedSession */
                $lockedSession = CheckoutSession::query()->where('id', $session->id)->lockForUpdate()->firstOrFail();
                $this->assertFreshCart($lockedSession);

                $cart = $lockedSession->cart;
                $this->cartService->applyCoupon($cart, $couponCode);
                $cart->refresh();
                $lockedSession->evaluated_cart_version = $cart->version;
                // Invalidate previously selected shipping quote on coupon change to prevent stale shipping benefits
                $lockedSession->selected_shipping_quote = null;

                $dest = $lockedSession->shipping_address !== null ? CheckoutAddress::fromArray($lockedSession->shipping_address) : null;

                $pricingRes = $this->pricingOrchestrator->calculate($cart, $dest, null);

                $lockedSession->pricing_snapshot = $pricingRes['pricing_snapshot'];
                $lockedSession->tax_snapshot = $pricingRes['tax_snapshot'];
                $lockedSession->promotion_snapshot = $pricingRes['promotion_snapshot'];

                $lockedSession->version++;
                $lockedSession->save();

                return ['session_id' => $session->id];
            }
        );

        $session->refresh();

        return $session;
    }

    public function removeCoupon(CheckoutSession $session, ?string $idempotencyKey = null): CheckoutSession
    {
        $this->assertFreshCart($session);

        $payload = [
            'action' => 'remove_coupon',
        ];

        $this->idempotencyService->execute(
            tenantId: $session->tenant_id,
            cartId: null,
            checkoutSessionId: $session->id,
            operationType: 'remove_coupon',
            idempotencyKey: $idempotencyKey,
            requestPayload: $payload,
            callback: function () use ($session) {
                /** @var CheckoutSession $lockedSession */
                $lockedSession = CheckoutSession::query()->where('id', $session->id)->lockForUpdate()->firstOrFail();
                if ($lockedSession->expires_at->isPast()) {
                    throw new CheckoutExpiredException("CHECKOUT_EXPIRED: Checkout session [{$lockedSession->id}] has expired at [{$lockedSession->expires_at->toIso8601String()}].");
                }
                $this->assertFreshCart($lockedSession);

                $cart = $lockedSession->cart;
                $this->cartService->removeCoupon($cart);
                $cart->refresh();
                $lockedSession->evaluated_cart_version = $cart->version;
                // Invalidate previously selected shipping quote on coupon change to prevent stale shipping benefits
                $lockedSession->selected_shipping_quote = null;

                $dest = $lockedSession->shipping_address !== null ? CheckoutAddress::fromArray($lockedSession->shipping_address) : null;

                $pricingRes = $this->pricingOrchestrator->calculate($cart, $dest, null);

                $lockedSession->pricing_snapshot = $pricingRes['pricing_snapshot'];
                $lockedSession->tax_snapshot = $pricingRes['tax_snapshot'];
                $lockedSession->promotion_snapshot = $pricingRes['promotion_snapshot'];

                $lockedSession->version++;
                $lockedSession->save();

                return ['session_id' => $session->id];
            }
        );

        $session->refresh();

        return $session;
    }

    public function getShippingRates(CheckoutSession $session): array
    {
        $this->assertFreshCart($session);

        if ($session->shipping_address === null) {
            throw new RuntimeException('Cannot quote shipping rates without a shipping address.');
        }

        $dest = CheckoutAddress::fromArray($session->shipping_address);

        return $this->shippingOrchestrator->quote($session->cart, $dest);
    }

    public function markReadyForOrder(CheckoutSession $session, ?string $idempotencyKey = null): CheckoutReadyResult
    {
        $this->licenseService->assertActiveForTenant($session->tenant_id);
        $this->expirationService->expireIfNeeded($session);
        $this->assertFreshCart($session);

        $payload = [
            'session_id' => $session->id,
            'cart_version' => $session->evaluated_cart_version,
            'pricing_snapshot' => $session->pricing_snapshot,
        ];

        try {
            $res = $this->idempotencyService->execute(
                tenantId: $session->tenant_id,
                cartId: null,
                checkoutSessionId: $session->id,
                operationType: 'ready',
                idempotencyKey: $idempotencyKey,
                requestPayload: $payload,
                callback: function () use ($session) {
                    /** @var CheckoutSession $lockedSession */
                    $lockedSession = CheckoutSession::query()->where('id', $session->id)->lockForUpdate()->firstOrFail();
                    if ($lockedSession->expires_at->isPast()) {
                        throw new CheckoutExpiredException("CHECKOUT_EXPIRED: Checkout session [{$lockedSession->id}] has expired at [{$lockedSession->expires_at->toIso8601String()}].");
                    }

                    $this->assertFreshCart($lockedSession);

                    $this->assertValidShippingQuote($lockedSession);

                    $this->stateMachine->assertCanTransition($lockedSession, 'ready_for_order');

                    $dest = $lockedSession->shipping_address !== null ? CheckoutAddress::fromArray($lockedSession->shipping_address) : null;
                    $quote = $lockedSession->selected_shipping_quote !== null ? SelectedShippingQuote::fromArray($lockedSession->selected_shipping_quote, $lockedSession->currency) : null;

                    // Final authoritative recalculation
                    $pricingRes = $this->pricingOrchestrator->calculate($lockedSession->cart, $dest, $quote);
                    $totals = $pricingRes['totals'];

                    $lockedSession->cart->loadMissing(['lines.product.translations', 'lines.variant']);

                    $lines = [];
                    $pricingByLineId = [];
                    if (isset($pricingRes['pricing_snapshot']['lines']) && is_array($pricingRes['pricing_snapshot']['lines'])) {
                        foreach ($pricingRes['pricing_snapshot']['lines'] as $pLine) {
                            if (is_array($pLine) && isset($pLine['cart_line_id'])) {
                                $pricingByLineId[(int) $pLine['cart_line_id']] = $pLine;
                            }
                        }
                    }

                    foreach ($lockedSession->cart->lines as $line) {
                        /** @var CartLine $line */
                        $product = $line->product;
                        $variant = $line->variant;
                        $sku = $variant !== null ? $variant->sku : $product->sku;
                        /** @var ProductTranslation|null $localeTranslation */
                        $localeTranslation = $product->translations->firstWhere('locale', $lockedSession->locale);
                        /** @var ProductTranslation|null $firstTranslation */
                        $firstTranslation = $product->translations->first();
                        $name = $localeTranslation !== null ? $localeTranslation->name : ($firstTranslation !== null ? $firstTranslation->name : $product->name);
                        $productType = $product->product_type;

                        $pLine = $pricingByLineId[$line->id] ?? null;

                        $vendorUuidSnapshot = null;
                        $vendorNameSnapshot = null;
                        $vendorListingUuidSnapshot = null;
                        $commissionBasisMinor = null;
                        $commissionRateBps = null;
                        $commissionFixedFeeMinor = null;
                        $commissionAmountMinor = null;
                        $commissionCurrency = null;
                        $commissionRuleRef = null;
                        $vendorId = null;
                        $vendorListingId = null;

                        $vendorListingUuid = $line->options['vendor_listing_uuid'] ?? ($line->customizations['vendor_listing_uuid'] ?? null);

                        if ($vendorListingUuid !== null && is_string($vendorListingUuid) && app()->bound(VendorListingResolutionServiceInterface::class)) {
                            /** @var VendorListingResolutionServiceInterface $listingResolver */
                            $listingResolver = app(VendorListingResolutionServiceInterface::class);
                            $resolvedListing = $listingResolver->resolveListingByUuid(
                                tenantId: $lockedSession->tenant_id,
                                storeId: $lockedSession->store_id,
                                vendorListingUuid: $vendorListingUuid,
                                productId: $line->product_id,
                                variantId: $line->variant_id
                            );

                            if ($resolvedListing !== null) {
                                $vendor = $resolvedListing->vendor;
                                $vendorUuidSnapshot = $vendor->uuid;
                                $vendorNameSnapshot = $vendor->name;
                                $vendorListingUuidSnapshot = $resolvedListing->uuid;
                                $vendorId = $vendor->id;
                                $vendorListingId = $resolvedListing->id;

                                if (app()->bound(VendorCommissionQuoteServiceInterface::class)) {
                                    /** @var VendorCommissionQuoteServiceInterface $commissionService */
                                    $commissionService = app(VendorCommissionQuoteServiceInterface::class);

                                    $merchSubtotal = $pLine !== null ? (int) $pLine['merchandise_line_subtotal_minor'] : 0;
                                    $lineDisc = $pLine !== null ? (int) $pLine['line_discount_minor'] : 0;
                                    $cartDisc = $pLine !== null ? (int) $pLine['allocated_cart_discount_minor'] : 0;
                                    $commBasis = max(0, $merchSubtotal - $lineDisc - $cartDisc);

                                    $curr = $lockedSession->currency;
                                    $catId = $product->categories()->first()?->id;

                                    $commQuote = $commissionService->quoteCommission(
                                        tenantId: $lockedSession->tenant_id,
                                        vendorId: $vendor->id,
                                        categoryId: $catId,
                                        basisMinor: $commBasis,
                                        currency: $curr
                                    );

                                    $commissionBasisMinor = $commQuote->basisMinor;
                                    $commissionRateBps = $commQuote->rateBps;
                                    $commissionFixedFeeMinor = $commQuote->fixedFeeMinor;
                                    $commissionAmountMinor = $commQuote->commissionAmountMinor;
                                    $commissionCurrency = $commQuote->currency;
                                    $commissionRuleRef = $commQuote->ruleReference;

                                    if (app()->bound(MarketplaceConcurrencyBarrierInterface::class)) {
                                        /** @var MarketplaceConcurrencyBarrierInterface $barrier */
                                        $barrier = app(MarketplaceConcurrencyBarrierInterface::class);
                                        $barrier->wait('commission_rule_resolved_before_checkout_snapshot_persist');
                                    }
                                }
                            }
                        }

                        $requiresShippingSnapshot = true;
                        if (app()->bound(ProductTypeRegistryInterface::class)) {
                            $productTypeDef = app(ProductTypeRegistryInterface::class)->get($productType);
                            $requiresShippingSnapshot = $productTypeDef->requiresShipping();
                        }

                        $lines[] = [
                            'cart_line_id' => $line->id,
                            'requires_shipping_snapshot' => $requiresShippingSnapshot,
                            'product_id' => $line->product_id,
                            'variant_id' => $line->variant_id,
                            'sku_snapshot' => $sku,
                            'name_snapshot' => $name,
                            'product_type_snapshot' => $productType,
                            'quantity' => (string) $line->quantity,
                            'signature' => $line->signature,
                            'options' => $line->options,
                            'customizations' => $line->customizations,
                            'unit_price_minor' => $pLine !== null ? (int) $pLine['unit_price_minor'] : null,
                            'merchandise_line_subtotal_minor' => $pLine !== null ? (int) $pLine['merchandise_line_subtotal_minor'] : null,
                            'line_discount_minor' => $pLine !== null ? (int) $pLine['line_discount_minor'] : 0,
                            'allocated_cart_discount_minor' => $pLine !== null ? (int) $pLine['allocated_cart_discount_minor'] : 0,
                            'taxable_amount_minor' => $pLine !== null ? (int) $pLine['taxable_amount_minor'] : null,
                            'tax_minor' => $pLine !== null ? (int) $pLine['tax_minor'] : 0,
                            'line_total_minor' => $pLine !== null ? (int) $pLine['line_total_minor'] : null,
                            'tax_class_id' => $pLine !== null ? (isset($pLine['tax_class_id']) ? (int) $pLine['tax_class_id'] : null) : null,
                            'tax_rate_percent' => $pLine !== null ? (isset($pLine['tax_rate_percent']) ? (string) $pLine['tax_rate_percent'] : null) : null,
                            'vendor_uuid_snapshot' => $vendorUuidSnapshot,
                            'vendor_name_snapshot' => $vendorNameSnapshot,
                            'vendor_listing_uuid_snapshot' => $vendorListingUuidSnapshot,
                            'commission_basis_minor' => $commissionBasisMinor,
                            'commission_rate_bps' => $commissionRateBps,
                            'commission_fixed_fee_minor' => $commissionFixedFeeMinor,
                            'commission_amount_minor' => $commissionAmountMinor,
                            'commission_currency' => $commissionCurrency,
                            'commission_rule_ref' => $commissionRuleRef,
                            'vendor_id' => $vendorId,
                            'vendor_listing_id' => $vendorListingId,
                        ];
                    }

                    $commercialModelSnapshot = 'platform_as_merchant_of_record';
                    if (app()->bound(MarketplaceCommercialPolicyInterface::class)) {
                        try {
                            $commercialModelSnapshot = app(MarketplaceCommercialPolicyInterface::class)
                                ->resolveModel($lockedSession->tenant_id, $lockedSession->store_id)->value;
                        } catch (\Throwable) {
                            $commercialModelSnapshot = 'platform_as_merchant_of_record';
                        }
                    }

                    $readyResult = new CheckoutReadyResult(
                        checkoutSessionId: $lockedSession->id,
                        checkoutUuid: $lockedSession->uuid,
                        tenantId: $lockedSession->tenant_id,
                        cartId: $lockedSession->cart_id,
                        cartVersion: $lockedSession->evaluated_cart_version,
                        context: [
                            'store_id' => $lockedSession->store_id,
                            'market_id' => $lockedSession->market_id,
                            'channel_id' => $lockedSession->channel_id,
                            'currency' => $lockedSession->currency,
                            'locale' => $lockedSession->locale,
                            'commercial_model_snapshot' => $commercialModelSnapshot,
                        ],
                        customerData: $lockedSession->customer_data ?? [],
                        shippingAddress: $lockedSession->shipping_address,
                        billingAddress: $lockedSession->billing_address,
                        lines: $lines,
                        totals: $totals->toArray(),
                        pricingSnapshot: $pricingRes['pricing_snapshot'],
                        taxSnapshot: $pricingRes['tax_snapshot'],
                        promotionSnapshot: $pricingRes['promotion_snapshot'],
                        fulfillmentSnapshot: $lockedSession->fulfillment_snapshot,
                        selectedShippingQuote: $lockedSession->selected_shipping_quote,
                        reservationReferences: array_values((array) ($lockedSession->reservation_references ?? [])),
                        state: 'ready_for_order',
                        finalizedAt: now()
                    );

                    $lockedSession->state = 'ready_for_order';
                    $lockedSession->ready_snapshot = $readyResult->toArray();
                    $lockedSession->version++;
                    $lockedSession->save();

                    return $readyResult->toArray();
                }
            );
        } catch (CheckoutExpiredException $e) {
            $this->expirationService->expireIfNeeded($session);
            throw $e;
        }

        return new CheckoutReadyResult(
            checkoutSessionId: (int) $res['checkout_session_id'],
            checkoutUuid: (string) $res['checkout_uuid'],
            tenantId: (int) $res['tenant_id'],
            cartId: (int) $res['cart_id'],
            cartVersion: (int) $res['cart_version'],
            context: (array) $res['context'],
            customerData: (array) $res['customer_data'],
            shippingAddress: $res['shipping_address'] ? (array) $res['shipping_address'] : null,
            billingAddress: $res['billing_address'] ? (array) $res['billing_address'] : null,
            lines: array_values((array) ($res['lines'] ?? [])),
            totals: (array) $res['totals'],
            pricingSnapshot: $res['pricing_snapshot'] ? (array) $res['pricing_snapshot'] : null,
            taxSnapshot: $res['tax_snapshot'] ? (array) $res['tax_snapshot'] : null,
            promotionSnapshot: $res['promotion_snapshot'] ? (array) $res['promotion_snapshot'] : null,
            fulfillmentSnapshot: is_array($res['fulfillment_snapshot'] ?? null) ? $res['fulfillment_snapshot'] : (is_string($res['fulfillment_snapshot'] ?? null) ? json_decode((string) $res['fulfillment_snapshot'], true) : null),
            selectedShippingQuote: $res['selected_shipping_quote'] ? (array) $res['selected_shipping_quote'] : null,
            reservationReferences: array_values((array) ($res['reservation_references'] ?? [])),
            state: (string) $res['state'],
            finalizedAt: Carbon::parse((string) $res['finalized_at'])
        );
    }

    public function cancel(CheckoutSession $session, ?string $idempotencyKey = null): bool
    {
        $payload = [
            'checkout_session_id' => $session->id,
            'version' => $session->version,
        ];

        $res = $this->idempotencyService->execute(
            tenantId: $session->tenant_id,
            cartId: null,
            checkoutSessionId: $session->id,
            operationType: 'cancel',
            idempotencyKey: $idempotencyKey,
            requestPayload: $payload,
            callback: function () use ($session) {
                /** @var CheckoutSession $lockedSession */
                $lockedSession = CheckoutSession::query()->where('id', $session->id)->lockForUpdate()->firstOrFail();

                $this->reservationOrchestrator->releaseAll($lockedSession);

                $lockedSession->state = 'cancelled';
                $lockedSession->reservation_references = null;
                $lockedSession->version++;
                $lockedSession->save();

                return ['cancelled' => true];
            }
        );

        return (bool) ($res['cancelled'] ?? true);
    }

    private function assertFreshCart(CheckoutSession $session): void
    {
        $cart = $session->cart;
        if ($cart->version !== $session->evaluated_cart_version) {
            throw new RuntimeException("CART_STALE: Cart was updated after checkout session was created. Re-evaluation required (cart version: {$cart->version}, evaluated: {$session->evaluated_cart_version}).");
        }
    }

    private function assertValidShippingQuote(CheckoutSession $session): void
    {
        if ($session->selected_shipping_quote === null) {
            return;
        }

        $quote = SelectedShippingQuote::fromArray($session->selected_shipping_quote, $session->currency);
        if ($quote->isExpired()) {
            throw new ShippingQuoteExpiredException("SHIPPING_QUOTE_EXPIRED: Selected shipping quote [{$quote->methodId}] has expired at [{$quote->expiresAt->toIso8601String()}]. Please fetch and select fresh shipping rates.");
        }

        if ($session->shipping_address !== null && $session->cart !== null) {
            $dest = CheckoutAddress::fromArray($session->shipping_address);
            $this->shippingOrchestrator->revalidateSelectedQuote($session->cart, $dest, $quote);
        }
    }
}
