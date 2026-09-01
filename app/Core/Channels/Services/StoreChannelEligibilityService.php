<?php

declare(strict_types=1);

namespace App\Core\Channels\Services;

use App\Core\Channels\Contracts\StoreChannelEligibilityInterface;
use App\Core\Channels\Models\StoreChannel;

class StoreChannelEligibilityService implements StoreChannelEligibilityInterface
{
    public function isEnabledForStore(?int $storeId, int $channelId): bool
    {
        if ($storeId === null) {
            return false;
        }

        return StoreChannel::query()
            ->where('store_id', $storeId)
            ->where('channel_id', $channelId)
            ->where('is_active', true)
            ->exists();
    }
}
