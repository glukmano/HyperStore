<div class="flex flex-col md:flex-row gap-6">
    @include('theme::components.account-nav')

    <div class="flex-1 space-y-4">
        <h1 class="text-2xl font-bold">{{ __('Loyalty Points') }}</h1>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <x-ui.card title="{{ __('Available') }}">
                <p class="text-3xl font-bold">{{ number_format($available) }}</p>
                <p class="text-sm text-base-content/60">{{ __('Redeemable now, at checkout.') }}</p>
            </x-ui.card>
            <x-ui.card title="{{ __('Pending') }}">
                <p class="text-3xl font-bold">{{ number_format($pending) }}</p>
                <p class="text-sm text-base-content/60">{{ __('Still in the hold period before becoming available.') }}</p>
            </x-ui.card>
        </div>

        @if ($nextExpiry !== null)
            <x-ui.alert variant="info">
                {{ __('Some points expire on :date.', ['date' => $nextExpiry->expires_at->format('Y-m-d')]) }}
            </x-ui.alert>
        @endif

        <x-ui.card title="{{ __('History') }}">
            <x-ui.table :headers="[__('Date'), __('Type'), __('Points')]" :empty="$history->isEmpty()" emptyMessage="{{ __('No point activity yet.') }}">
                @foreach ($history as $entry)
                    <tr wire:key="entry-{{ $entry->id }}">
                        <td>{{ $entry->created_at->format('Y-m-d') }}</td>
                        <td>{{ str_replace('_', ' ', $entry->entry_type) }}</td>
                        <td class="{{ $entry->points >= 0 ? 'text-success' : 'text-error' }}">{{ $entry->points >= 0 ? '+' : '' }}{{ $entry->points }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    </div>
</div>
