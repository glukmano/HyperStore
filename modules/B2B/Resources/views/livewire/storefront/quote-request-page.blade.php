<div class="space-y-4">
    <h1 class="text-2xl font-bold">{{ __('Quotes') }}</h1>

    @if ($errorMessage)
        <x-ui.alert variant="error">{{ $errorMessage }}</x-ui.alert>
    @endif
    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    @if ($companyUser === null)
        <x-ui.alert variant="warning">{{ __('You are not a member of a Company account.') }}</x-ui.alert>
    @else
        <x-ui.card title="{{ __('Request a Quote') }}">
            <form wire:submit="submitRfq" class="flex items-end gap-3">
                <x-ui.input label="{{ __('Product SKU') }}" wire:model="productSku" />
                <x-ui.input type="number" label="{{ __('Quantity') }}" wire:model="quantity" />
                <x-ui.button type="submit" variant="primary">{{ __('Request Quote') }}</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card title="{{ __('Your Quotes') }}">
            <x-ui.table :headers="[__('Status'), __('Lines'), '']" :empty="$quotes->isEmpty()" emptyMessage="{{ __('No quotes yet.') }}">
                @foreach ($quotes as $quote)
                    <tr wire:key="quote-{{ $quote->id }}">
                        <td>{{ ucfirst($quote->status->value) }}</td>
                        <td>{{ $quote->lines->count() }}</td>
                        <td>
                            @if ($quote->status->value === 'quoted')
                                <x-ui.button wire:click="acceptQuote({{ $quote->id }})" variant="primary" size="sm">{{ __('Accept & Add to Cart') }}</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif
</div>
