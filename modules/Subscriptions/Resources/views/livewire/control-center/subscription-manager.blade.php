<div class="space-y-6">
    <h1 class="text-2xl font-bold tracking-tight text-base-content">Subscriptions</h1>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="All Subscriptions">
        <x-ui.table :headers="['Customer', 'Plan', 'Status', 'Next Billing', 'Renewal Attempts', '']" :empty="$subscriptions->isEmpty()" emptyMessage="No subscriptions yet.">
            @foreach ($subscriptions as $subscription)
                <tr wire:key="sub-{{ $subscription->id }}">
                    <td>{{ $subscription->customerProfile?->user?->email }}</td>
                    <td>{{ $subscription->plan?->name }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $subscription->status->value)) }}</td>
                    <td>{{ $subscription->next_billing_at->format('Y-m-d') }}</td>
                    <td>{{ $subscription->renewalAttempts->count() }}</td>
                    <td class="flex gap-2">
                        @if ($subscription->status->value !== 'cancelled')
                            <x-ui.button wire:click="cancelNow({{ $subscription->id }})" variant="ghost" size="sm">Cancel</x-ui.button>
                        @endif
                        @if (in_array($subscription->status->value, ['past_due', 'suspended']))
                            <x-ui.button wire:click="reactivate({{ $subscription->id }})" variant="primary" size="sm">Reactivate</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
