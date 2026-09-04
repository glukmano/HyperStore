<div class="space-y-6">
    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Vendors' => null]" />

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Vendors</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    @if (session()->has('error'))
        <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
    @endif

    <x-ui.card title="All Vendors">
        <x-ui.table :headers="['Name', 'Platform Slug', 'Operational Status', 'Verification Status', 'Plan', '']" :empty="$vendors->isEmpty()" emptyMessage="No vendors found.">
            @foreach ($vendors as $vendor)
                <tr wire:key="vendor-{{ $vendor->id }}">
                    <td class="font-medium">{{ $vendor->name }}</td>
                    <td class="font-mono text-xs">{{ $vendor->platform_slug }}</td>
                    <td>
                        <x-ui.badge variant="{{ match ($vendor->operational_status->value) {
                            'active' => 'success',
                            'suspended' => 'warning',
                            'terminated' => 'danger',
                            'pending_approval' => 'accent',
                            default => 'ghost',
                        } }}">
                            {{ $vendor->operational_status->value }}
                        </x-ui.badge>
                    </td>
                    <td>
                        <x-ui.badge variant="{{ match ($vendor->verification_status->value) {
                            'verified' => 'success',
                            'pending' => 'accent',
                            'rejected' => 'danger',
                            'needs_review' => 'warning',
                            default => 'ghost',
                        } }}">
                            {{ $vendor->verification_status->value }}
                        </x-ui.badge>
                    </td>
                    <td>{{ $vendor->plan?->name ?? '—' }}</td>
                    <td class="text-end">
                        <a href="{{ route('control-center.vendors.show', ['vendorId' => $vendor->id]) }}" wire:navigate class="btn btn-ghost btn-sm">View</a>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pagination :paginator="$vendors" />
    </x-ui.card>
</div>
