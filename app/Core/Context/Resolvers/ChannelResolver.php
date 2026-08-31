<?php

declare(strict_types=1);

namespace App\Core\Context\Resolvers;

use App\Core\Channels\Models\Channel;
use App\Core\Context\Contracts\ChannelContextInterface;
use App\Core\Context\Contracts\ChannelResolverInterface;
use App\Core\Context\DTOs\ChannelContext;
use Illuminate\Http\Request;

class ChannelResolver implements ChannelResolverInterface
{
    public function __construct(
        private readonly ?Request $request = null,
    ) {}

    public function resolve(): ChannelContextInterface
    {
        if ($this->request === null) {
            return ChannelContext::unresolved();
        }

        $headerHandle = $this->request->header('X-Channel-Handle');
        if ($headerHandle !== null && $headerHandle !== '') {
            $channel = Channel::query()->where('handle', $headerHandle)->where('is_active', true)->first();
            if ($channel !== null) {
                return ChannelContext::from($channel->id, $channel->handle);
            }
        }

        $queryHandle = $this->request->query('channel');
        if (is_string($queryHandle) && $queryHandle !== '') {
            $channel = Channel::query()->where('handle', $queryHandle)->where('is_active', true)->first();
            if ($channel !== null) {
                return ChannelContext::from($channel->id, $channel->handle);
            }
        }

        $websiteChannel = Channel::query()->where('handle', 'website')->where('is_active', true)->first();
        if ($websiteChannel !== null) {
            return ChannelContext::from($websiteChannel->id, $websiteChannel->handle);
        }

        return ChannelContext::unresolved();
    }
}
