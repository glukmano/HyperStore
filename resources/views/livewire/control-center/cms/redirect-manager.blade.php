<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('Redirects') }}</h1>
    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Redirects' => null]" />

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card>
        <x-ui.table :headers="[__('From'), __('To'), __('Code'), __('Active'), '']" :empty="$redirects->isEmpty()" emptyMessage="{{ __('No redirects yet.') }}">
            @foreach ($redirects as $redirect)
                <tr wire:key="redirect-{{ $redirect->id }}">
                    <td>{{ $redirect->from_path }}</td>
                    <td>{{ $redirect->to_path }}</td>
                    <td>{{ $redirect->status_code }}</td>
                    <td>
                        <x-ui.badge variant="{{ $redirect->is_active ? 'success' : 'neutral' }}">
                            {{ $redirect->is_active ? __('Active') : __('Inactive') }}
                        </x-ui.badge>
                    </td>
                    <td class="text-end">
                        <x-ui.button size="sm" variant="ghost" wire:click="toggleActive({{ $redirect->id }})">
                            {{ $redirect->is_active ? __('Disable') : __('Enable') }}
                        </x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$redirects" />
    </x-ui.card>

    <x-ui.card>
        <h2 class="font-semibold mb-3">{{ __('New Redirect') }}</h2>
        @if($error)
            <x-ui.alert variant="danger">{{ $error }}</x-ui.alert>
        @endif
        <form wire:submit="create" class="flex items-end gap-2 flex-wrap">
            <x-ui.input wire:model="fromPath" label="{{ __('From path') }}" placeholder="/old-page" />
            <x-ui.input wire:model="toPath" label="{{ __('To path') }}" placeholder="/new-page" />
            <div class="form-control">
                <label class="label"><span class="label-text">{{ __('Status Code') }}</span></label>
                <select wire:model="statusCode" class="select select-bordered">
                    <option value="301">301</option>
                    <option value="302">302</option>
                </select>
            </div>
            <label class="label cursor-pointer gap-2">
                <input type="checkbox" wire:model="isExternal" class="checkbox" />
                <span class="label-text">{{ __('External URL') }}</span>
            </label>
            <x-ui.button type="submit" variant="primary">{{ __('Create') }}</x-ui.button>
        </form>
    </x-ui.card>
</div>
