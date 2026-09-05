<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold">{{ __('Messaging Moderation') }}</h1>
        <p class="text-sm text-base-content/60">{{ __('Review and close buyer/vendor conversations.') }}</p>
    </div>

    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Messaging Moderation' => null]" />

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.tabs :tabs="['open' => __('Open'), 'closed' => __('Closed'), 'archived' => __('Archived')]" :active="$statusFilter" switchAction="setStatusFilter" />

    <x-ui.card>
        <x-ui.table :headers="[__('Subject'), __('Vendor'), __('Participants'), __('Last Activity'), '']" :empty="$conversations->isEmpty()" emptyMessage="{{ __('No conversations in this status.') }}">
            @foreach ($conversations as $conversation)
                <tr wire:key="conv-{{ $conversation->id }}">
                    <td>{{ $conversation->subject ?? __('(no subject)') }}</td>
                    <td>{{ $conversation->vendor?->name ?? '—' }}</td>
                    <td>{{ $conversation->participants->pluck('user.name')->filter()->implode(', ') }}</td>
                    <td>{{ $conversation->last_message_at?->diffForHumans() }}</td>
                    <td class="text-end">
                        @if($conversation->status === 'open')
                            <x-ui.button size="sm" variant="danger" wire:click="close({{ $conversation->id }})">{{ __('Close') }}</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$conversations" />
    </x-ui.card>
</div>
