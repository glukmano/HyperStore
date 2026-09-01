<?php

declare(strict_types=1);

namespace Modules\Cart\Services;

use App\Core\Channels\Contracts\StoreChannelEligibilityInterface;
use App\Core\Stores\Models\Store;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\Models\Cart;
use Modules\Cart\Models\CartLine;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Catalog\Contracts\ProductTypeRegistryInterface;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;
use RuntimeException;

class CartService implements CartServiceInterface
{
    public function __construct(
        private readonly StoreChannelEligibilityInterface $channelEligibility,
        private readonly ProductTypeRegistryInterface $capabilityResolver
    ) {}

    public function getOrCreateActiveCart(CartContext $context): Cart
    {
        // 1. Validate Store belongs to Tenant
        $store = Store::query()
            ->where('id', $context->storeId)
            ->where('tenant_id', $context->tenantId)
            ->first();

        if ($store === null) {
            throw new InvalidArgumentException("Store [{$context->storeId}] does not belong to Tenant [{$context->tenantId}].");
        }

        // 2. Validate StoreChannel eligibility
        if (! $this->channelEligibility->isEnabledForStore($context->storeId, $context->channelId)) {
            throw new InvalidArgumentException("Channel [{$context->channelId}] is not enabled for Store [{$context->storeId}].");
        }

        $guestHash = $context->getGuestTokenHash();

        if ($context->userId !== null) {
            $cart = Cart::query()
                ->where('tenant_id', $context->tenantId)
                ->where('user_id', $context->userId)
                ->where('store_id', $context->storeId)
                ->where('market_id', $context->marketId)
                ->where('channel_id', $context->channelId)
                ->where('status', 'active')
                ->where('expires_at', '>', Carbon::now())
                ->first();

            if ($cart !== null) {
                return $cart;
            }

            try {
                return Cart::create([
                    'tenant_id' => $context->tenantId,
                    'user_id' => $context->userId,
                    'guest_token_hash' => null,
                    'store_id' => $context->storeId,
                    'market_id' => $context->marketId,
                    'channel_id' => $context->channelId,
                    'currency' => $context->currency,
                    'locale' => $context->locale,
                    'status' => 'active',
                ]);
            } catch (QueryException $e) {
                // Retry lookup if created concurrently
                $existing = Cart::query()
                    ->where('tenant_id', $context->tenantId)
                    ->where('user_id', $context->userId)
                    ->where('store_id', $context->storeId)
                    ->where('market_id', $context->marketId)
                    ->where('channel_id', $context->channelId)
                    ->where('status', 'active')
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }
                throw $e;
            }
        }

        if ($guestHash !== null) {
            $cart = Cart::query()
                ->where('tenant_id', $context->tenantId)
                ->where('guest_token_hash', $guestHash)
                ->where('store_id', $context->storeId)
                ->where('market_id', $context->marketId)
                ->where('channel_id', $context->channelId)
                ->where('status', 'active')
                ->where('expires_at', '>', Carbon::now())
                ->first();

            if ($cart !== null) {
                return $cart;
            }

            try {
                return Cart::create([
                    'tenant_id' => $context->tenantId,
                    'user_id' => null,
                    'guest_token_hash' => $guestHash,
                    'store_id' => $context->storeId,
                    'market_id' => $context->marketId,
                    'channel_id' => $context->channelId,
                    'currency' => $context->currency,
                    'locale' => $context->locale,
                    'status' => 'active',
                ]);
            } catch (QueryException $e) {
                $existing = Cart::query()
                    ->where('tenant_id', $context->tenantId)
                    ->where('guest_token_hash', $guestHash)
                    ->where('store_id', $context->storeId)
                    ->where('market_id', $context->marketId)
                    ->where('channel_id', $context->channelId)
                    ->where('status', 'active')
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }
                throw $e;
            }
        }

        throw new InvalidArgumentException('Cart context must provide either authenticated userId or guestToken.');
    }

    public function addLine(Cart $cart, CartLineItemData $itemData, ?int $expectedVersion = null): CartLine
    {
        if (! $cart->isActive()) {
            throw new RuntimeException("Cannot mutate inactive or expired Cart [{$cart->id}].");
        }

        // 1. Resolve and validate product through Catalog
        /** @var Product|null $product */
        $product = Product::query()
            ->where('id', $itemData->productId)
            ->where('tenant_id', $cart->tenant_id)
            ->first();

        if ($product === null) {
            throw new InvalidArgumentException("Product [{$itemData->productId}] not found in Tenant [{$cart->tenant_id}].");
        }

        if ($product->status !== 'active') {
            throw new InvalidArgumentException("Product [{$product->id}] is not purchasable (status: {$product->status}).");
        }

        if ($itemData->variantId !== null) {
            /** @var ProductVariant|null $variant */
            $variant = ProductVariant::query()
                ->where('id', $itemData->variantId)
                ->where('product_id', $product->id)
                ->first();

            if ($variant === null) {
                throw new InvalidArgumentException("Variant [{$itemData->variantId}] not found for Product [{$product->id}].");
            }
        }

        // 2. Validate quantity capability
        $itemData->quantity->validateCapability($product, $this->capabilityResolver);

        $signature = $itemData->computeSignature();

        return DB::transaction(function () use ($cart, $itemData, $signature, $expectedVersion) {
            $this->assertAndIncrementVersion($cart, $expectedVersion);

            /** @var CartLine|null $existingLine */
            $existingLine = CartLine::query()
                ->where('cart_id', $cart->id)
                ->where('signature', $signature)
                ->lockForUpdate()
                ->first();

            if ($existingLine !== null) {
                $newQty = $existingLine->getQuantityVO()->add($itemData->quantity);
                $existingLine->update([
                    'quantity' => $newQty->value(),
                    'options' => $itemData->options,
                    'customizations' => $itemData->customizations,
                    'metadata' => $itemData->metadata,
                ]);

                return $existingLine;
            }

            return CartLine::create([
                'cart_id' => $cart->id,
                'product_id' => $itemData->productId,
                'variant_id' => $itemData->variantId,
                'quantity' => $itemData->quantity->value(),
                'signature' => $signature,
                'options' => $itemData->options,
                'customizations' => $itemData->customizations,
                'metadata' => $itemData->metadata,
            ]);
        });
    }

    public function updateQuantity(Cart $cart, int $lineId, CartQuantity $quantity, ?int $expectedVersion = null): CartLine
    {
        if (! $cart->isActive()) {
            throw new RuntimeException("Cannot mutate inactive or expired Cart [{$cart->id}].");
        }

        return DB::transaction(function () use ($cart, $lineId, $quantity, $expectedVersion) {
            $this->assertAndIncrementVersion($cart, $expectedVersion);

            /** @var CartLine $line */
            $line = CartLine::query()
                ->where('id', $lineId)
                ->where('cart_id', $cart->id)
                ->with('product')
                ->lockForUpdate()
                ->firstOrFail();

            $quantity->validateCapability($line->product, $this->capabilityResolver);

            $line->update(['quantity' => $quantity->value()]);

            return $line;
        });
    }

    public function removeLine(Cart $cart, int $lineId, ?int $expectedVersion = null): bool
    {
        if (! $cart->isActive()) {
            throw new RuntimeException("Cannot mutate inactive or expired Cart [{$cart->id}].");
        }

        return DB::transaction(function () use ($cart, $lineId, $expectedVersion) {
            $this->assertAndIncrementVersion($cart, $expectedVersion);

            return CartLine::query()
                ->where('id', $lineId)
                ->where('cart_id', $cart->id)
                ->delete() > 0;
        });
    }

    public function applyCoupon(Cart $cart, string $couponCode, ?int $expectedVersion = null): Cart
    {
        if (! $cart->isActive()) {
            throw new RuntimeException("Cannot mutate inactive or expired Cart [{$cart->id}].");
        }

        return DB::transaction(function () use ($cart, $couponCode, $expectedVersion) {
            $this->assertAndIncrementVersion($cart, $expectedVersion);

            $cart->coupon_code = strtoupper(trim($couponCode));
            $cart->save();

            return $cart;
        });
    }

    public function removeCoupon(Cart $cart, ?int $expectedVersion = null): Cart
    {
        if (! $cart->isActive()) {
            throw new RuntimeException("Cannot mutate inactive or expired Cart [{$cart->id}].");
        }

        return DB::transaction(function () use ($cart, $expectedVersion) {
            $this->assertAndIncrementVersion($cart, $expectedVersion);

            $cart->coupon_code = null;
            $cart->save();

            return $cart;
        });
    }

    public function clear(Cart $cart, ?int $expectedVersion = null): bool
    {
        if (! $cart->isActive()) {
            throw new RuntimeException("Cannot mutate inactive or expired Cart [{$cart->id}].");
        }

        return DB::transaction(function () use ($cart, $expectedVersion) {
            $this->assertAndIncrementVersion($cart, $expectedVersion);

            CartLine::query()->where('cart_id', $cart->id)->delete();
            $cart->coupon_code = null;
            $cart->save();

            return true;
        });
    }

    public function mergeGuestCart(Cart $guestCart, Cart $customerCart): Cart
    {
        if ($guestCart->tenant_id !== $customerCart->tenant_id) {
            throw new InvalidArgumentException('Cannot merge carts across different tenants.');
        }

        return DB::transaction(function () use ($guestCart, $customerCart) {
            $guestLines = CartLine::query()->where('cart_id', $guestCart->id)->get();

            foreach ($guestLines as $gLine) {
                /** @var CartLine $gLine */
                $itemData = new CartLineItemData(
                    productId: $gLine->product_id,
                    variantId: $gLine->variant_id,
                    quantity: $gLine->getQuantityVO(),
                    options: $gLine->options ?? [],
                    customizations: $gLine->customizations ?? [],
                    metadata: $gLine->metadata ?? []
                );

                $this->addLine($customerCart, $itemData);
            }

            if ($customerCart->coupon_code === null && $guestCart->coupon_code !== null) {
                $customerCart->coupon_code = $guestCart->coupon_code;
                $customerCart->save();
            }

            $guestCart->status = 'converted';
            $guestCart->save();

            return $customerCart;
        });
    }

    public function expire(Cart $cart): bool
    {
        $cart->status = 'expired';

        return $cart->save();
    }

    private function assertAndIncrementVersion(Cart $cart, ?int $expectedVersion): void
    {
        if ($expectedVersion !== null) {
            $affected = Cart::query()
                ->where('id', $cart->id)
                ->where('version', $expectedVersion)
                ->update(['version' => $expectedVersion + 1]);

            if ($affected === 0) {
                throw new RuntimeException("Cart version mismatch. Expected [{$expectedVersion}], but Cart was modified concurrently.");
            }

            $cart->version = $expectedVersion + 1;
        } else {
            $cart->incrementVersion();
        }
    }
}
