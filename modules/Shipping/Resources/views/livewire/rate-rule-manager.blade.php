<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Rate Rules</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="Table Rate Rules &amp; Conditions">
        <x-ui.table :headers="['Method', 'Priority', 'Condition Type', 'Action Type', 'Stop Processing', 'Created']" :empty="$rules->isEmpty()" emptyMessage="No rate rules found.">
            @foreach ($rules as $rule)
                <tr wire:key="rate-rule-{{ $rule->id }}">
                    <td>{{ $rule->method?->name ?? '—' }}</td>
                    <td>{{ $rule->priority }}</td>
                    <td><x-ui.badge variant="ghost">{{ $rule->condition_type }}</x-ui.badge></td>
                    <td><x-ui.badge variant="ghost">{{ $rule->action_type }}</x-ui.badge></td>
                    <td>{{ $rule->stop_processing ? 'Yes' : 'No' }}</td>
                    <td class="text-xs">{{ $rule->created_at?->format('Y-m-d H:i') }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
