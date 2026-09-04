<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">{{ __('Plugins') }}</h1>
            <p class="text-sm text-base-content/60">{{ __('Platform-level plugin installation and lifecycle management.') }}</p>
        </div>
    </div>

    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Plugins' => null]" />

    @if ($installError)
        <x-ui.alert variant="error">{{ $installError }}</x-ui.alert>
    @endif

    <x-ui.card :title="__('Install a Plugin Package')">
        <form wire:submit.prevent="installPackage" class="flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[240px]">
                <label class="label"><span class="label-text">{{ __('Plugin ZIP package') }}</span></label>
                <input type="file" wire:model="packageFile" accept=".zip" class="file-input file-input-bordered w-full" />
                @error('packageFile') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
            </div>
            <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Validate & Install') }}</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card>
        <x-ui.table :headers="[__('Plugin'), __('Version'), __('Status'), __('Trust'), '']" :empty="$plugins->isEmpty()" emptyMessage="{{ __('No plugins installed.') }}">
            @foreach ($plugins as $plugin)
                <tr wire:key="plugin-{{ $plugin->id }}">
                    <td>
                        <div class="font-semibold">{{ $plugin->name }}</div>
                        <div class="text-xs font-mono text-base-content/50">{{ $plugin->plugin_id }}</div>
                    </td>
                    <td>{{ $plugin->version }}</td>
                    <td>
                        <x-ui.badge variant="{{ match(true) {
                            $plugin->status === 'enabled' => 'success',
                            $plugin->status === 'failed' => 'danger',
                            $plugin->status === 'disabled' => 'ghost',
                            default => 'warning',
                        } }}">{{ $plugin->status }}</x-ui.badge>
                    </td>
                    <td>
                        <x-ui.badge variant="{{ $plugin->trust_level === 'official' ? 'primary' : ($plugin->trust_level === 'verified_third_party' ? 'success' : 'ghost') }}">
                            {{ $plugin->trust_level }}
                        </x-ui.badge>
                    </td>
                    <td class="text-end">
                        <a href="{{ route('control-center.platform.plugins.show', ['pluginId' => $plugin->plugin_id]) }}" wire:navigate class="btn btn-sm btn-ghost">{{ __('Manage') }}</a>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
