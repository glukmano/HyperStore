<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-base-content">Payments</h1>
            <p class="text-sm text-base-content/60">Read-only view of payments across this tenant</p>
        </div>
    </div>

    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Payments' => null]" />

    <x-ui.card>
        <div class="mb-4 md:w-64">
            <x-ui.select wire:model.live="statusFilter" placeholder="All Statuses">
                @foreach ($statuses as $status)
                    <option value="{{ $status }}">{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                @endforeach
            </x-ui.select>
        </div>

        <x-ui.table :headers="['Payment UUID', 'Order #', 'Status', 'Amount', 'Captured', 'Refunded', 'Created At', '']" :empty="$payments->isEmpty()" emptyMessage="No payments found.">
            @foreach ($payments as $payment)
                <tr>
                    <td class="font-mono text-xs">{{ $payment->uuid }}</td>
                    <td class="font-mono text-xs">{{ $payment->order?->order_number ?? '—' }}</td>
                    <td>
                        <x-ui.badge variant="{{ match ($payment->status) {
                            'captured' => 'success',
                            'authorized' => 'primary',
                            'cancelled' => 'danger',
                            'refunded', 'partially_refunded' => 'warning',
                            default => 'ghost',
                        } }}">{{ str_replace('_', ' ', $payment->status) }}</x-ui.badge>
                    </td>
                    <td>{{ number_format($payment->amount_minor / 100, 2) }} {{ $payment->currency }}</td>
                    <td>{{ number_format($payment->captured_amount_minor / 100, 2) }} {{ $payment->currency }}</td>
                    <td>{{ number_format($payment->refunded_amount_minor / 100, 2) }} {{ $payment->currency }}</td>
                    <td>{{ $payment->created_at->format('Y-m-d H:i') }}</td>
                    <td class="text-end">
                        <a href="{{ route('control-center.payments.show', $payment->uuid) }}" class="btn btn-sm btn-ghost" wire:navigate>View</a>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pagination :paginator="$payments" class="mt-4" />
    </x-ui.card>
</div>
