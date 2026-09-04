<div class="space-y-6">
    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Plugins' => route('control-center.platform.plugins.index'), $plugin->name => null]" />

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">{{ $plugin->name }}</h1>
            <p class="text-sm font-mono text-base-content/50">{{ $plugin->plugin_id }} · v{{ $plugin->version }}</p>
        </div>
        <div class="flex items-center gap-2">
            <x-ui.badge variant="{{ match(true) {
                $plugin->status === 'enabled' => 'success',
                $plugin->status === 'failed' => 'danger',
                $plugin->status === 'disabled' => 'ghost',
                default => 'warning',
            } }}">{{ $plugin->status }}</x-ui.badge>
            <x-ui.badge variant="{{ $plugin->trust_level === 'official' ? 'primary' : ($plugin->trust_level === 'verified_third_party' ? 'success' : 'ghost') }}">
                {{ $plugin->trust_level }}
            </x-ui.badge>
        </div>
    </div>

    @if ($plugin->failure_reason)
        <x-ui.alert variant="error">{{ $plugin->failure_reason }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <x-ui.card :title="__('Manifest')">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-base-content/60">{{ __('Author') }}</dt><dd>{{ $plugin->manifest_snapshot['author'] ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-base-content/60">{{ __('License') }}</dt><dd>{{ $plugin->manifest_snapshot['license'] ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-base-content/60">{{ __('Namespace') }}</dt><dd class="font-mono text-xs">{{ $plugin->manifest_snapshot['namespace'] ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-base-content/60">{{ __('Platform compatibility') }}</dt><dd>{{ $plugin->manifest_snapshot['compatibility']['platform'] ?? '*' }}</dd></div>
                <div class="flex justify-between"><dt class="text-base-content/60">{{ __('PHP compatibility') }}</dt><dd>{{ $plugin->manifest_snapshot['compatibility']['php'] ?? '*' }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card :title="__('Capabilities & Permissions')">
            <p class="text-xs uppercase text-base-content/50 mb-1">{{ __('Requested capabilities') }}</p>
            <div class="flex flex-wrap gap-1 mb-3">
                @forelse ($plugin->manifest_snapshot['capabilities'] ?? [] as $capability)
                    <x-ui.badge variant="{{ in_array($capability, $plugin->granted_permissions ?? [], true) ? 'success' : 'warning' }}">{{ $capability }}</x-ui.badge>
                @empty
                    <span class="text-sm text-base-content/50">{{ __('None declared.') }}</span>
                @endforelse
            </div>
            @if ($plugin->permissions_approved_at === null)
                <x-ui.button size="sm" wire:click="approvePermissions">{{ __('Approve Capabilities') }}</x-ui.button>
            @else
                <p class="text-xs text-base-content/50">{{ __('Approved') }} {{ $plugin->permissions_approved_at->diffForHumans() }}</p>
            @endif

            <p class="text-xs uppercase text-base-content/50 mt-4 mb-1">{{ __('User permissions this plugin registers') }}</p>
            <div class="flex flex-wrap gap-1">
                @forelse ($plugin->manifest_snapshot['requested_permissions'] ?? [] as $permission)
                    <x-ui.badge variant="ghost">{{ $permission }}</x-ui.badge>
                @empty
                    <span class="text-sm text-base-content/50">{{ __('None.') }}</span>
                @endforelse
            </div>
        </x-ui.card>
    </div>

    <x-ui.card :title="__('Lifecycle')">
        <div class="flex flex-wrap gap-3">
            @if ($plugin->status !== 'enabled')
                <x-ui.button variant="primary" wire:click="enable">{{ __('Enable') }}</x-ui.button>
            @else
                <x-ui.button variant="warning" wire:click="disable">{{ __('Disable') }}</x-ui.button>
            @endif
            <x-ui.button variant="danger" wire:click="openUninstallConfirm">{{ __('Uninstall') }}</x-ui.button>
        </div>
        <div class="mt-4 text-sm text-base-content/60 space-y-1">
            <p>{{ __('Installed') }}: {{ $plugin->installed_at?->format('Y-m-d H:i') ?? '—' }}</p>
            <p>{{ __('Enabled') }}: {{ $plugin->enabled_at?->format('Y-m-d H:i') ?? '—' }}</p>
            <p>{{ __('Consecutive boot failures') }}: {{ $plugin->consecutive_boot_failures }}</p>
            <p>{{ __('Last migration batch') }}: {{ $plugin->last_migration_batch ?? '—' }}</p>
        </div>
    </x-ui.card>

    <x-ui.confirm-dialog
        :show="$confirmingUninstall"
        title="{{ __('Uninstall plugin?') }}"
        message="{{ __('By default the plugin\'s data is kept. Check the box below to also permanently delete its data.') }}"
        confirm-action="uninstall"
        cancel-action="cancelUninstallConfirm"
        confirm-label="{{ __('Uninstall') }}"
        variant="danger"
    />
    @if ($confirmingUninstall)
        <div class="fixed inset-x-0 bottom-6 flex justify-center z-[60]">
            <label class="flex items-center gap-2 bg-base-100 border border-base-300 rounded-box px-4 py-2 shadow-lg">
                <input type="checkbox" wire:model="purgeDataOnUninstall" class="checkbox checkbox-sm checkbox-error" />
                <span class="text-sm">{{ __('Also permanently delete this plugin\'s data (--purge-data)') }}</span>
            </label>
        </div>
    @endif
</div>
