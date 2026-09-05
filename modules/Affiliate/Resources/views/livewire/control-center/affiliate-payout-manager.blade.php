<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Affiliate Payouts</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="Payout Requests">
        <x-ui.table :headers="['Affiliate', 'Amount', 'Currency', 'Status', '']" :empty="$requests->isEmpty()" emptyMessage="No payout requests yet.">
            @foreach ($requests as $request)
                <tr wire:key="payout-{{ $request->id }}">
                    <td>{{ $request->affiliate->display_name }}</td>
                    <td>{{ number_format($request->amount_minor / 100, 2) }}</td>
                    <td>{{ $request->currency }}</td>
                    <td><x-ui.badge variant="neutral">{{ ucfirst($request->status->value) }}</x-ui.badge></td>
                    <td class="flex gap-2">
                        @if ($request->status->value === 'requested')
                            <x-ui.button wire:click="approve({{ $request->id }})" variant="primary" size="sm">Approve</x-ui.button>
                            <x-ui.button wire:click="cancel({{ $request->id }})" variant="ghost" size="sm">Cancel</x-ui.button>
                        @elseif ($request->status->value === 'approved')
                            <x-ui.button wire:click="markProcessing({{ $request->id }})" variant="primary" size="sm">Mark Processing</x-ui.button>
                        @elseif ($request->status->value === 'processing')
                            <x-ui.button wire:click="finalize({{ $request->id }})" variant="primary" size="sm">Finalize</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
