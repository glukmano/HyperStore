<div class="space-y-6">
    <h1 class="text-2xl font-bold tracking-tight text-base-content">Auctions</h1>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="New Auction">
        <form wire:submit="createAuction" class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <x-ui.input type="number" label="Product ID" wire:model="productId" />
            <x-ui.input label="Currency" wire:model="currency" />
            <x-ui.input type="datetime-local" label="Starts At (UTC)" wire:model="startsAt" />
            <x-ui.input type="datetime-local" label="Ends At (UTC)" wire:model="endsAt" />
            <x-ui.input type="number" label="Starting Price (minor)" wire:model="startingPriceMinor" />
            <x-ui.input type="number" label="Bid Increment (minor)" wire:model="bidIncrementMinor" />
            <x-ui.input type="number" label="Reserve Price (minor, optional)" wire:model="reservePriceMinor" />
            <x-ui.input type="number" label="Payment Window (minutes)" wire:model="winnerPaymentWindowMinutes" />
            <div class="md:col-span-4">
                <x-ui.button type="submit" variant="primary">Schedule Auction</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card title="Auctions">
        <x-ui.table :headers="['Product', 'Status', 'Current Price', 'Current Bidder', '']" :empty="$auctions->isEmpty()" emptyMessage="No auctions yet.">
            @foreach ($auctions as $auction)
                <tr wire:key="auction-{{ $auction->id }}">
                    <td>{{ $auction->product->sku ?? $auction->product_id }}</td>
                    <td><x-ui.badge variant="{{ $auction->status->value === 'active' ? 'success' : ($auction->status->value === 'scheduled' ? 'warning' : 'ghost') }}">{{ ucfirst($auction->status->value) }}</x-ui.badge></td>
                    <td>{{ number_format(($auction->current_price_minor ?? $auction->starting_price_minor) / 100, 2) }} {{ $auction->currency }}</td>
                    <td>{{ $auction->currentBid?->bidder?->user?->name ?? '—' }}</td>
                    <td>
                        @if (in_array($auction->status->value, ['scheduled', 'active']))
                            <x-ui.button wire:click="cancelAuction({{ $auction->id }})" variant="ghost" size="sm">Cancel</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
