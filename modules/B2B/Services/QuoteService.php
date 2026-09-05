<?php

declare(strict_types=1);

namespace Modules\B2B\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\B2B\Enums\CompanyUserRole;
use Modules\B2B\Enums\QuoteStatus;
use Modules\B2B\Exceptions\QuoteNotAcceptableException;
use Modules\B2B\Models\Company;
use Modules\B2B\Models\Quote;
use Modules\B2B\Models\QuoteLine;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\Models\Cart;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;

final class QuoteService
{
    public function __construct(
        private readonly CartServiceInterface $cartService,
        private readonly CompanyAuthorizationService $authorizationService,
    ) {}

    /**
     * @param  array<int, array{product_id: int, variant_id: ?int, quantity: string, note?: ?string}>  $lines
     */
    public function submitRfq(Company $company, User $user, string $currency, array $lines): Quote
    {
        $this->authorizationService->assertRole(
            (int) $company->tenant_id,
            (int) $company->id,
            (int) $user->id,
            [CompanyUserRole::Owner, CompanyUserRole::Buyer, CompanyUserRole::Approver],
            'submit_rfq'
        );

        return DB::transaction(function () use ($company, $user, $currency, $lines): Quote {
            $quote = Quote::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                'status' => QuoteStatus::Submitted,
                'currency' => $currency,
                'created_by_user_id' => $user->id,
            ]);

            foreach ($lines as $line) {
                QuoteLine::create([
                    'tenant_id' => $company->tenant_id,
                    'quote_id' => $quote->id,
                    'product_id' => $line['product_id'],
                    'variant_id' => $line['variant_id'] ?? null,
                    'quantity' => $line['quantity'],
                    'note' => $line['note'] ?? null,
                ]);
            }

            return $quote;
        });
    }

    /**
     * Control Center only: staff sets negotiated prices per line.
     *
     * @param  array<int, int>  $lineIdToPriceMinor
     */
    public function quotePrices(Quote $quote, User $staffUser, array $lineIdToPriceMinor, ?CarbonImmutable $validUntil = null): void
    {
        DB::transaction(function () use ($quote, $staffUser, $lineIdToPriceMinor, $validUntil): void {
            foreach ($lineIdToPriceMinor as $lineId => $priceMinor) {
                QuoteLine::where('id', $lineId)
                    ->where('quote_id', $quote->id)
                    ->update(['negotiated_unit_price_minor' => $priceMinor]);
            }

            $quote->update([
                'status' => QuoteStatus::Quoted,
                'quoted_by_user_id' => $staffUser->id,
                'valid_until' => $validUntil,
            ]);
        });
    }

    public function reject(Quote $quote): void
    {
        $quote->update(['status' => QuoteStatus::Rejected]);
    }

    /**
     * Owner Delta §3: builds a real Cart via the existing CartService, then
     * sets `quote_line_id` on each resulting CartLine SERVER-SIDE only —
     * this is the sole path a negotiated price is ever attached to a Cart
     * line. No request/Livewire input can set/change quote_line_id.
     */
    public function acceptAndBuildCart(Quote $quote, User $buyer, CartContext $cartContext): Cart
    {
        $this->authorizationService->assertRole(
            (int) $quote->tenant_id,
            (int) $quote->company_id,
            (int) $buyer->id,
            [CompanyUserRole::Owner, CompanyUserRole::Approver],
            'accept_quote'
        );

        if (! $quote->isAcceptable()) {
            throw QuoteNotAcceptableException::forQuote((int) $quote->id, 'status is not quoted, or it has expired');
        }

        return DB::transaction(function () use ($quote, $cartContext): Cart {
            $quote->refresh();
            if (! $quote->isAcceptable()) {
                throw QuoteNotAcceptableException::forQuote((int) $quote->id, 'status is not quoted, or it has expired');
            }

            $cart = $this->cartService->getOrCreateActiveCart($cartContext);

            foreach ($quote->lines as $quoteLine) {
                /** @var QuoteLine $quoteLine */
                // Each QuoteLine is commercially distinct (its own negotiated
                // price) even when it shares a Product/Variant with another
                // line on the SAME Quote — the quote_line_id customization
                // key keeps CartService::addLine()'s own signature-based
                // merge from ever collapsing two differently-priced lines
                // into one, so prices can never cross-map (Owner Delta §3).
                $cartLine = $this->cartService->addLine($cart, new CartLineItemData(
                    productId: (int) $quoteLine->product_id,
                    variantId: $quoteLine->variant_id !== null ? (int) $quoteLine->variant_id : null,
                    quantity: CartQuantity::fromString((string) $quoteLine->quantity),
                    customizations: ['quote_line_id' => $quoteLine->id],
                ));

                $cartLine->update(['quote_line_id' => $quoteLine->id]);
            }

            $quote->update(['status' => QuoteStatus::Accepted]);

            return $cart->refresh();
        });
    }

    public function expireStaleQuotes(int $tenantId, ?CarbonImmutable $asOf = null): int
    {
        $cutoff = $asOf ?? CarbonImmutable::now();

        return Quote::where('tenant_id', $tenantId)
            ->where('status', QuoteStatus::Quoted)
            ->whereNotNull('valid_until')
            ->where('valid_until', '<=', $cutoff)
            ->update(['status' => QuoteStatus::Expired]);
    }
}
