<?php

declare(strict_types=1);

namespace Modules\Promotions\Actions;

use Modules\Pricing\Contracts\CurrencyConversionInterface;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Promotions\Contracts\PromotionActionInterface;
use Modules\Promotions\DTOs\DiscountLine;
use Modules\Promotions\DTOs\PromotionContext;
use Modules\Promotions\Models\Promotion;

class FixedDiscountAction implements PromotionActionInterface
{
    public function __construct(
        private readonly ?CurrencyConversionInterface $conversionService = null,
    ) {}

    public function getType(): string
    {
        return 'fixed_discount';
    }

    public function apply(Promotion $promotion, PromotionContext $context, array $parameters, MoneyValue $currentTotal): ?DiscountLine
    {
        $discountMinor = (int) ($parameters['amount_minor'] ?? 0);
        $discountCurrency = strtoupper((string) ($parameters['currency'] ?? $context->currency));

        $discount = MoneyValue::fromMinor($discountMinor, $discountCurrency);

        // Multi-currency safety: convert if discount currency differs from cart currency
        if ($discountCurrency !== $context->currency && $this->conversionService !== null) {
            $discount = $this->conversionService->convert($discount, $context->currency, $context->tenantId);
        }

        if ($discount->isGreaterThan($currentTotal)) {
            $discount = $currentTotal;
        }

        return new DiscountLine(
            promotionId: $promotion->id,
            promotionCode: $promotion->code,
            description: "Fixed discount of {$discount->format()}",
            discountAmount: $discount
        );
    }
}
