<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Affiliates</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="Affiliates">
        <x-ui.table :headers="['Name', 'Status', 'Currency', 'Withdrawable', 'Applied', '']" :empty="$affiliates->isEmpty()" emptyMessage="No affiliates yet.">
            @foreach ($affiliates as $affiliate)
                <tr wire:key="aff-{{ $affiliate->id }}">
                    <td>{{ $affiliate->display_name }}</td>
                    <td><x-ui.badge variant="{{ $affiliate->status->value === 'active' ? 'success' : ($affiliate->status->value === 'pending' ? 'warning' : 'ghost') }}">{{ ucfirst($affiliate->status->value) }}</x-ui.badge></td>
                    <td>{{ $affiliate->payout_currency }}</td>
                    <td>{{ number_format(($balances[$affiliate->id]->withdrawableBalanceMinor ?? 0) / 100, 2) }}</td>
                    <td>{{ $affiliate->applied_at->format('Y-m-d') }}</td>
                    <td class="flex gap-2">
                        @if ($affiliate->status->value === 'pending')
                            <x-ui.button wire:click="approve({{ $affiliate->id }})" variant="primary" size="sm">Approve</x-ui.button>
                            <x-ui.button wire:click="reject({{ $affiliate->id }})" variant="ghost" size="sm">Reject</x-ui.button>
                        @elseif ($affiliate->status->value === 'active')
                            <x-ui.button wire:click="suspend({{ $affiliate->id }})" variant="ghost" size="sm">Suspend</x-ui.button>
                        @elseif ($affiliate->status->value === 'suspended')
                            <x-ui.button wire:click="approve({{ $affiliate->id }})" variant="primary" size="sm">Reactivate</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card title="Open Fraud Flags">
        <x-ui.table :headers="['Affiliate', 'Type', 'Detected', '']" :empty="$openFlags->isEmpty()" emptyMessage="No open fraud flags.">
            @foreach ($openFlags as $flag)
                <tr wire:key="flag-{{ $flag->id }}">
                    <td>{{ $flag->affiliate->display_name }}</td>
                    <td><x-ui.badge variant="warning">{{ str_replace('_', ' ', $flag->flag_type->value) }}</x-ui.badge></td>
                    <td>{{ $flag->detected_at->format('Y-m-d H:i') }}</td>
                    <td>
                        <x-ui.button wire:click="resolveFlag({{ $flag->id }}, 'reviewed_ok')" variant="ghost" size="sm">Resolve</x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
