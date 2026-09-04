<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Inventory Reconciliation Manager</h1>
        <x-ui.button wire:click="runReconciliation" wire:loading.attr="disabled">Run Reconciliation</x-ui.button>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    @if ($report === null)
        <x-ui.card>
            <x-ui.empty-state message="No reconciliation has been run yet. Click 'Run Reconciliation' to audit stock balances against the movement ledger." />
        </x-ui.card>
    @else
        <x-ui.alert :variant="$report['is_clean'] ? 'success' : 'warning'">
            {{ $report['is_clean'] ? 'All inventory balances are consistent.' : 'Discrepancies were found. Review the tables below.' }}
        </x-ui.alert>

        <x-ui.stats :items="[
            ['label' => 'Stock Items Audited', 'value' => $report['total_stock_items']],
            ['label' => 'Balance Discrepancies', 'value' => count($report['balance_discrepancies'])],
            ['label' => 'Reservation Discrepancies', 'value' => count($report['reservation_discrepancies'])],
            ['label' => 'Orphan Allocations', 'value' => $report['orphan_allocations_count']],
        ]" />

        <x-ui.card title="On-Hand Balance Discrepancies">
            <x-ui.table :headers="['Stock Item', 'On Hand', 'Expected On Hand', 'Drift']" :empty="empty($report['balance_discrepancies'])" emptyMessage="No on-hand discrepancies found.">
                @foreach ($report['balance_discrepancies'] as $row)
                    <tr wire:key="balance-{{ $row['stock_item_id'] }}">
                        <td>#{{ $row['stock_item_id'] }}</td>
                        <td>{{ $row['on_hand'] }}</td>
                        <td>{{ $row['expected_on_hand'] }}</td>
                        <td><x-ui.badge variant="danger">{{ $row['drift'] }}</x-ui.badge></td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="Reservation Discrepancies">
            <x-ui.table :headers="['Stock Item', 'Reserved', 'Expected Reserved', 'Drift']" :empty="empty($report['reservation_discrepancies'])" emptyMessage="No reservation discrepancies found.">
                @foreach ($report['reservation_discrepancies'] as $row)
                    <tr wire:key="reservation-{{ $row['stock_item_id'] }}">
                        <td>#{{ $row['stock_item_id'] }}</td>
                        <td>{{ $row['reserved'] }}</td>
                        <td>{{ $row['expected_reserved'] }}</td>
                        <td><x-ui.badge variant="danger">{{ $row['drift'] }}</x-ui.badge></td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif
</div>
