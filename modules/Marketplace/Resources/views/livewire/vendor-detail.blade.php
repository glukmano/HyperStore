@php
    use Modules\Marketplace\Enums\VendorOperationalStatus;
@endphp

<div class="space-y-6">
    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Vendors' => route('control-center.vendors.index'), $vendor->name => null]" />

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">{{ $vendor->name }}</h1>
        <x-ui.badge variant="{{ match ($vendor->operational_status->value) {
            'active' => 'success',
            'suspended' => 'warning',
            'terminated' => 'danger',
            'pending_approval' => 'accent',
            default => 'ghost',
        } }}">
            {{ $vendor->operational_status->value }}
        </x-ui.badge>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    @if (session()->has('error'))
        <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
    @endif

    <x-ui.stats :items="[
        ['label' => 'Verification', 'value' => $vendor->verification_status->value],
        ['label' => 'Plan', 'value' => $vendor->plan?->name ?? '—'],
        ['label' => 'Payout Currency', 'value' => $vendor->payout_currency],
        ['label' => 'Default Store', 'value' => $vendor->defaultStore?->name ?? '—'],
    ]" />

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <x-ui.card title="Vendor Details">
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <dt class="text-xs uppercase text-base-content/60">Legal Name</dt>
                        <dd class="font-medium">{{ $vendor->legal_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-base-content/60">Platform Slug</dt>
                        <dd class="font-mono text-sm">{{ $vendor->platform_slug }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-base-content/60">Email</dt>
                        <dd class="font-medium">{{ $vendor->email }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-base-content/60">Phone</dt>
                        <dd class="font-medium">{{ $vendor->phone ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-base-content/60">Tax ID</dt>
                        <dd class="font-medium">{{ $vendor->tax_id ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-base-content/60">Submitted At</dt>
                        <dd class="font-medium">{{ $vendor->submitted_at?->toDayDateTimeString() ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-base-content/60">Approved At</dt>
                        <dd class="font-medium">{{ $vendor->approved_at?->toDayDateTimeString() ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-base-content/60">Suspended At</dt>
                        <dd class="font-medium">{{ $vendor->suspended_at?->toDayDateTimeString() ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-base-content/60">Terminated At</dt>
                        <dd class="font-medium">{{ $vendor->terminated_at?->toDayDateTimeString() ?? '—' }}</dd>
                    </div>
                </dl>
            </x-ui.card>
        </div>

        <div class="lg:col-span-1">
            <x-ui.card title="Status Actions">
                <div class="flex flex-col gap-3">
                    @if ($vendor->operational_status === VendorOperationalStatus::PendingApproval)
                        <x-ui.button variant="success" wire:click="openApproveConfirm">Approve</x-ui.button>
                    @endif

                    @if ($vendor->operational_status->canTransitionTo(VendorOperationalStatus::Suspended) && $vendor->operational_status !== VendorOperationalStatus::Suspended)
                        <x-ui.button variant="warning" wire:click="openSuspendConfirm">Suspend</x-ui.button>
                    @endif

                    @if ($vendor->operational_status === VendorOperationalStatus::Suspended)
                        <x-ui.button variant="success" wire:click="openReactivateConfirm">Reactivate</x-ui.button>
                    @endif

                    @if ($vendor->operational_status->canTransitionTo(VendorOperationalStatus::Terminated) && $vendor->operational_status !== VendorOperationalStatus::Terminated)
                        <x-ui.button variant="danger" wire:click="openTerminateConfirm">Terminate</x-ui.button>
                    @endif

                    @if ($vendor->operational_status->isTerminal())
                        <p class="text-sm text-base-content/60">This vendor is terminated. No further transitions are possible.</p>
                    @endif
                </div>
            </x-ui.card>
        </div>
    </div>

    <x-ui.confirm-dialog
        :show="$showApproveConfirm"
        title="Approve Vendor"
        message="Approve {{ $vendor->name }} and move it to Active status?"
        confirmAction="approve"
        cancelAction="cancelConfirm"
        confirmLabel="Approve"
        variant="success"
    />

    <x-ui.confirm-dialog
        :show="$showSuspendConfirm"
        title="Suspend Vendor"
        message="Suspend {{ $vendor->name }}? The vendor will be unable to sell until reactivated."
        confirmAction="suspend"
        cancelAction="cancelConfirm"
        confirmLabel="Suspend"
        variant="warning"
    />

    <x-ui.confirm-dialog
        :show="$showReactivateConfirm"
        title="Reactivate Vendor"
        message="Reactivate {{ $vendor->name }} and restore Active status?"
        confirmAction="reactivate"
        cancelAction="cancelConfirm"
        confirmLabel="Reactivate"
        variant="success"
    />

    <x-ui.confirm-dialog
        :show="$showTerminateConfirm"
        title="Terminate Vendor"
        message="Terminate {{ $vendor->name }}? This action is permanent and cannot be undone."
        confirmAction="terminate"
        cancelAction="cancelConfirm"
        confirmLabel="Terminate"
        variant="danger"
    />
</div>
