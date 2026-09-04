<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Reservation Manager</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="Stock Reservations">
        <x-ui.table :headers="['Reservation Key', 'Status', 'Owner', 'Allocations', 'Total Qty', 'Expires At']" :empty="$reservations->isEmpty()" emptyMessage="No stock reservations found.">
            @foreach ($reservations as $reservation)
                <tr wire:key="reservation-{{ $reservation->id }}">
                    <td class="font-mono text-xs">{{ $reservation->reservation_key }}</td>
                    <td>
                        <x-ui.badge variant="{{ match($reservation->status) {
                            'active' => 'success',
                            'committed' => 'primary',
                            'released', 'expired' => 'ghost',
                            default => 'ghost',
                        } }}">
                            {{ $reservation->status }}
                        </x-ui.badge>
                    </td>
                    <td class="text-xs">
                        @if ($reservation->owner_type)
                            {{ $reservation->owner_type }} — {{ $reservation->owner_reference }}
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $reservation->allocations->count() }}</td>
                    <td>{{ $reservation->getTotalQuantity()->toString() }}</td>
                    <td class="text-xs">{{ $reservation->expires_at?->format('Y-m-d H:i') ?? 'Never' }}</td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pagination :paginator="$reservations" />
    </x-ui.card>
</div>
