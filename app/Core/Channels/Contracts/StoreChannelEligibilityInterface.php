<?php

declare(strict_types=1);

namespace App\Core\Channels\Contracts;

interface StoreChannelEligibilityInterface
{
    /**
     * Determine whether a channel is enabled and active for a given store.
     */
    public function isEnabledForStore(?int $storeId, int $channelId): bool;
}
