<?php

declare(strict_types=1);

namespace Modules\Promotions\Conditions;

use Modules\Promotions\Contracts\PromotionConditionInterface;
use Modules\Promotions\DTOs\PromotionContext;

class ChannelCondition implements PromotionConditionInterface
{
    public function getType(): string
    {
        return 'channel';
    }

    public function evaluate(PromotionContext $context, array $parameters): bool
    {
        $channelIds = $parameters['channel_ids'] ?? [];

        return $context->channelId !== null && in_array($context->channelId, $channelIds, true);
    }
}
