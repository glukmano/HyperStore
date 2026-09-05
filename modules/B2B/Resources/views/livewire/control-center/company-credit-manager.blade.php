<div class="space-y-6">
    <h1 class="text-2xl font-bold tracking-tight text-base-content">Company Credit</h1>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="Credit Exposure">
        <x-ui.table :headers="['Company', 'Currency', 'Limit', 'Outstanding Exposure', 'Available']" :empty="$accounts->isEmpty()" emptyMessage="No Company credit accounts yet.">
            @foreach ($accounts as $account)
                <tr wire:key="account-{{ $account->id }}">
                    <td>{{ $account->company->name }}</td>
                    <td>{{ $account->currency }}</td>
                    <td>{{ number_format($account->approved_limit_minor / 100, 2) }}</td>
                    <td>{{ number_format($balances[$account->id]['outstanding'] / 100, 2) }}</td>
                    <td>{{ number_format($balances[$account->id]['available'] / 100, 2) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card title="Open Invoices">
        <x-ui.table :headers="['Company', 'Amount', 'Due', '']" :empty="$openInvoices->isEmpty()" emptyMessage="No open invoices.">
            @foreach ($openInvoices as $invoice)
                <tr wire:key="invoice-{{ $invoice->id }}">
                    <td>{{ $invoice->company->name }}</td>
                    <td>{{ number_format($invoice->amount_minor / 100, 2) }} {{ $invoice->currency }}</td>
                    <td>{{ $invoice->due_at->format('Y-m-d') }}</td>
                    <td class="flex gap-2">
                        <x-ui.button wire:click="markInvoicePaid({{ $invoice->id }})" variant="primary" size="sm">Mark Paid</x-ui.button>
                        <x-ui.button wire:click="cancelInvoice({{ $invoice->id }})" variant="ghost" size="sm">Cancel</x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
