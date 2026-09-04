<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-base-content">Payment {{ $payment->uuid }}</h1>
            <p class="text-sm text-base-content/60">Order {{ $payment->order?->order_number ?? '—' }}</p>
        </div>
        <a href="{{ route('control-center.payments.index') }}" class="btn btn-ghost btn-sm" wire:navigate>Back to Payments</a>
    </div>

    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Payments' => route('control-center.payments.index'), $payment->uuid => null]" />

    <x-ui.stats :items="[
        ['label' => 'Status', 'value' => ucfirst(str_replace('_', ' ', $payment->status))],
        ['label' => 'Amount', 'value' => number_format($payment->amount_minor / 100, 2) . ' ' . $payment->currency],
        ['label' => 'Authorized', 'value' => number_format($payment->authorized_amount_minor / 100, 2) . ' ' . $payment->currency],
        ['label' => 'Captured', 'value' => number_format($payment->captured_amount_minor / 100, 2) . ' ' . $payment->currency],
        ['label' => 'Refunded', 'value' => number_format($payment->refunded_amount_minor / 100, 2) . ' ' . $payment->currency],
    ]" />

    <x-ui.card title="Timeline">
        <x-ui.table :headers="['Event', 'At']" :zebra="false">
            <tr>
                <td>Authorized</td>
                <td>{{ $payment->authorized_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
            </tr>
            <tr>
                <td>Captured</td>
                <td>{{ $payment->captured_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
            </tr>
            <tr>
                <td>Cancelled</td>
                <td>{{ $payment->cancelled_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
            </tr>
        </x-ui.table>
    </x-ui.card>

    <x-ui.card title="Transaction History">
        <x-ui.table :headers="['UUID', 'Operation', 'Status', 'Amount', 'Provider', 'Method', 'Provider Ref', 'Action Type', 'Error Code', 'Created At']" :empty="$payment->transactions->isEmpty()" emptyMessage="No transactions recorded.">
            @foreach ($payment->transactions as $transaction)
                <tr>
                    <td class="font-mono text-xs">{{ $transaction->uuid }}</td>
                    <td>{{ str_replace('_', ' ', $transaction->operation_type) }}</td>
                    <td>
                        <x-ui.badge variant="{{ match ($transaction->status) {
                            'success' => 'success',
                            'failure' => 'danger',
                            'action_required' => 'warning',
                            'unknown' => 'ghost',
                            default => 'neutral',
                        } }}">{{ str_replace('_', ' ', $transaction->status) }}</x-ui.badge>
                    </td>
                    <td>{{ number_format($transaction->amount_minor / 100, 2) }} {{ $transaction->currency }}</td>
                    <td>{{ $transaction->provider_code ?? '—' }}</td>
                    <td>{{ $transaction->payment_method_type ?? '—' }}</td>
                    <td class="font-mono text-xs">{{ $transaction->provider_reference ?? '—' }}</td>
                    <td>{{ $transaction->action_type ?? '—' }}</td>
                    <td>{{ $transaction->normalized_error_code ?? '—' }}</td>
                    <td>{{ $transaction->created_at->format('Y-m-d H:i:s') }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    @if ($payment->metadata)
        <x-ui.card title="Metadata">
            <pre class="text-xs whitespace-pre-wrap break-all bg-base-200 p-3 rounded-box">{{ json_encode($payment->metadata, JSON_PRETTY_PRINT) }}</pre>
        </x-ui.card>
    @endif
</div>
