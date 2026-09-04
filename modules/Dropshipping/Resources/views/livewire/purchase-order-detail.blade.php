<div class="space-y-6">
    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Purchase Orders' => route('control-center.dropshipping.purchase-orders.index'), $purchaseOrder->po_number => null]" />

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">{{ $purchaseOrder->po_number }}</h1>
        <x-ui.badge variant="{{ match ($purchaseOrder->status) {
            'draft' => 'ghost',
            'submitted' => 'accent',
            'confirmed' => 'primary',
            'fulfilled' => 'success',
            'cancelled' => 'danger',
            'rejected' => 'danger',
            default => 'neutral',
        } }}">
            {{ $purchaseOrder->status }}
        </x-ui.badge>
    </div>

    <x-ui.alert variant="info">
        This purchase order is a dropship PO (type: {{ $purchaseOrder->type }}). This view is read-only —
        the platform's Dropship Order Orchestrator does not currently expose any status-transition action,
        so no mutating controls are shown here.
    </x-ui.alert>

    <x-ui.stats :items="[
        ['label' => 'Supplier', 'value' => $purchaseOrder->supplier?->name ?? '—'],
        ['label' => 'Subtotal', 'value' => number_format($purchaseOrder->subtotal_minor / 100, 2) . ' ' . $purchaseOrder->currency],
        ['label' => 'Tax', 'value' => number_format($purchaseOrder->tax_minor / 100, 2) . ' ' . $purchaseOrder->currency],
        ['label' => 'Shipping', 'value' => number_format($purchaseOrder->shipping_minor / 100, 2) . ' ' . $purchaseOrder->currency],
        ['label' => 'Total', 'value' => number_format($purchaseOrder->total_minor / 100, 2) . ' ' . $purchaseOrder->currency],
    ]" />

    <x-ui.card title="Purchase Order Details">
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <dt class="text-xs uppercase text-base-content/60">Supplier</dt>
                <dd class="font-medium">{{ $purchaseOrder->supplier?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase text-base-content/60">Linked Fulfillment</dt>
                <dd class="font-mono text-sm">{{ $purchaseOrder->fulfillment?->uuid ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase text-base-content/60">Submitted At</dt>
                <dd class="font-medium">{{ $purchaseOrder->submitted_at?->toDayDateTimeString() ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase text-base-content/60">Acknowledged At</dt>
                <dd class="font-medium">{{ $purchaseOrder->acknowledged_at?->toDayDateTimeString() ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase text-base-content/60">Expected At</dt>
                <dd class="font-medium">{{ $purchaseOrder->expected_at?->toDayDateTimeString() ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase text-base-content/60">Shipped At</dt>
                <dd class="font-medium">{{ $purchaseOrder->shipped_at?->toDayDateTimeString() ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase text-base-content/60">Delivered At</dt>
                <dd class="font-medium">{{ $purchaseOrder->delivered_at?->toDayDateTimeString() ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase text-base-content/60">Cancelled At</dt>
                <dd class="font-medium">{{ $purchaseOrder->cancelled_at?->toDayDateTimeString() ?? '—' }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-xs uppercase text-base-content/60">Notes</dt>
                <dd class="font-medium">{{ $purchaseOrder->notes ?? '—' }}</dd>
            </div>
        </dl>
    </x-ui.card>

    <x-ui.card title="Line Items">
        <x-ui.table :headers="['Supplier SKU', 'Internal SKU', 'Quantity', 'Unit Cost', 'Total Cost']" :empty="$purchaseOrder->lines->isEmpty()" emptyMessage="No line items on this purchase order.">
            @foreach ($purchaseOrder->lines as $line)
                <tr wire:key="po-line-{{ $line->id }}">
                    <td class="font-mono text-xs">{{ $line->supplier_sku }}</td>
                    <td class="font-mono text-xs">{{ $line->internal_sku_snapshot }}</td>
                    <td>{{ $line->quantity }}</td>
                    <td>{{ number_format($line->unit_cost_minor / 100, 2) }} {{ $purchaseOrder->currency }}</td>
                    <td>{{ number_format($line->total_cost_minor / 100, 2) }} {{ $purchaseOrder->currency }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card title="Supplier Invoices &amp; Reconciliation">
        <x-ui.table :headers="['Invoice #', 'Status', 'Reconciliation', 'Total', 'Issued', 'Paid']" :empty="$purchaseOrder->invoices->isEmpty()" emptyMessage="No supplier invoices recorded against this purchase order.">
            @foreach ($purchaseOrder->invoices as $invoice)
                <tr wire:key="invoice-{{ $invoice->id }}">
                    <td class="font-mono text-xs">{{ $invoice->invoice_number }}</td>
                    <td>
                        <x-ui.badge variant="ghost">{{ $invoice->status }}</x-ui.badge>
                    </td>
                    <td>
                        <x-ui.badge variant="{{ match ($invoice->reconciliation_status) {
                            'matched' => 'success',
                            'pending' => 'accent',
                            'discrepancy' => 'warning',
                            'rejected' => 'danger',
                            default => 'neutral',
                        } }}">
                            {{ $invoice->reconciliation_status }}
                        </x-ui.badge>
                    </td>
                    <td>{{ number_format($invoice->total_minor / 100, 2) }} {{ $invoice->currency }}</td>
                    <td>{{ $invoice->issued_at?->format('Y-m-d') ?? '—' }}</td>
                    <td>{{ $invoice->paid_at?->format('Y-m-d') ?? '—' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
