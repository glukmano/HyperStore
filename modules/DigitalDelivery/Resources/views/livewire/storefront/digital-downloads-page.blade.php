<div class="space-y-6">
    <h1 class="text-2xl font-bold tracking-tight text-base-content">My Downloads</h1>

    <x-ui.card title="Available Downloads">
        <x-ui.table :headers="['Granted', 'Remaining Uses', 'Expires', '']" :empty="$entitlements->isEmpty()" emptyMessage="No downloads yet.">
            @foreach ($entitlements as $entitlement)
                <tr wire:key="ent-{{ $entitlement->id }}">
                    <td>{{ $entitlement->granted_at->format('Y-m-d') }}</td>
                    <td>{{ $entitlement->max_uses !== null ? max(0, $entitlement->max_uses - $entitlement->used_count) : 'Unlimited' }}</td>
                    <td>{{ $entitlement->expires_at?->format('Y-m-d') ?? 'Never' }}</td>
                    <td>
                        @if ($entitlement->isAccessible())
                            <a href="{{ $downloadUrls[$entitlement->id] }}" class="btn btn-primary btn-sm">Download</a>
                        @else
                            <span class="text-base-content/50">Unavailable</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
