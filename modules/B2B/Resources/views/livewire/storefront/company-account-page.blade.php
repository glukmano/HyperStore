<div class="space-y-4">
    <h1 class="text-2xl font-bold">{{ __('Company Account') }}</h1>

    @if ($company === null)
        <x-ui.alert variant="warning">{{ __('You are not a member of a Company account.') }}</x-ui.alert>
    @else
        <x-ui.card title="{{ $company->name }}">
            <p class="text-sm text-base-content/70">{{ __('Status') }}: {{ ucfirst($company->status->value) }}</p>
            <p class="text-sm text-base-content/70">{{ __('Payment terms') }}: {{ $company->payment_terms_days !== null ? "Net {$company->payment_terms_days}" : __('Prepaid') }}</p>
        </x-ui.card>

        <x-ui.card title="{{ __('Company Staff') }}">
            <x-ui.table :headers="[__('Name'), __('Role')]">
                @foreach ($company->companyUsers as $cu)
                    <tr wire:key="cu-{{ $cu->id }}">
                        <td>{{ $cu->user->name }}</td>
                        <td>{{ ucfirst($cu->role->value) }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif
</div>
