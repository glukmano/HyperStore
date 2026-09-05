<div>
    @if ($auction !== null)
        <x-ui.card title="{{ __('Auction') }}">
            @if ($errorMessage)
                <x-ui.alert variant="error">{{ $errorMessage }}</x-ui.alert>
            @endif

            <p class="text-2xl font-bold">
                {{ number_format(($auction->current_price_minor ?? $auction->starting_price_minor) / 100, 2) }} {{ $auction->currency }}
            </p>
            <p class="text-sm text-base-content/60">{{ __('Ends at') }}: {{ $auction->ends_at->format('Y-m-d H:i') }} UTC</p>

            @if ($auction->status->value === 'active')
                <div class="flex items-end gap-2 mt-3">
                    <x-ui.input type="number" step="0.01" label="{{ __('Your bid') }}" wire:model="bidAmount" />
                    <x-ui.button wire:click="placeBid" variant="primary">{{ __('Place Bid') }}</x-ui.button>
                </div>
            @elseif ($isWinner)
                <x-ui.alert variant="success">{{ __('You won this Auction!') }}</x-ui.alert>
                <x-ui.button wire:click="proceedToWinnerCheckout" variant="primary">{{ __('Proceed to Checkout') }}</x-ui.button>
            @endif

            @if ($bids->isNotEmpty())
                <h3 class="font-semibold mt-4 mb-2">{{ __('Bid History') }}</h3>
                <x-ui.table :headers="[__('Bidder'), __('Amount'), __('Placed At')]">
                    @foreach ($bids as $bid)
                        <tr wire:key="bid-{{ $bid->id }}">
                            <td>{{ $bid->bidder->user->name ?? __('Bidder') }}</td>
                            <td>{{ number_format($bid->amount_minor / 100, 2) }}</td>
                            <td>{{ $bid->placed_at->format('Y-m-d H:i:s') }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>
    @endif
</div>
