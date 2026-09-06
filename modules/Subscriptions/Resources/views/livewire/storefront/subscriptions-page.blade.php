<div class="space-y-6">
    <h1 class="text-2xl font-bold tracking-tight text-base-content">My Subscriptions</h1>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="Active Subscriptions">
        <x-ui.table :headers="['Plan', 'Status', 'Next Billing', '']" :empty="$subscriptions->isEmpty()" emptyMessage="You have no subscriptions.">
            @foreach ($subscriptions as $subscription)
                <tr wire:key="my-sub-{{ $subscription->id }}">
                    <td>{{ $subscription->plan?->name }}</td>
                    <td>
                        {{ ucfirst(str_replace('_', ' ', $subscription->status->value)) }}
                        @if ($subscription->cancel_at_period_end) (cancelling) @endif
                    </td>
                    <td>{{ $subscription->next_billing_at->format('Y-m-d') }}</td>
                    <td>
                        @if (! $subscription->cancel_at_period_end && $subscription->status->value !== 'cancelled')
                            <x-ui.button wire:click="cancelAtPeriodEnd({{ $subscription->id }})" variant="ghost" size="sm">Cancel</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
