<div class="max-w-lg mx-auto space-y-6">
    <h1 class="text-2xl font-bold tracking-tight text-base-content">POS Register</h1>

    @if ($errorMessage)
        <x-ui.alert variant="error">{{ $errorMessage }}</x-ui.alert>
    @endif
    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    @if ($activeSession)
        <x-ui.card title="Close Session #{{ $activeSession->id }}">
            <form wire:submit="close" class="space-y-4">
                <div>
                    <label class="label">Counted Cash</label>
                    <input type="text" wire:model="closingCounted" class="input input-bordered w-full" />
                </div>
                <x-ui.button type="submit" variant="primary">Close Session</x-ui.button>
            </form>
        </x-ui.card>
    @else
        <x-ui.card title="Open Register">
            <form wire:submit="open" class="space-y-4">
                <div>
                    <label class="label">Register</label>
                    <select wire:model="registerId" class="select select-bordered w-full">
                        <option value="0">Select...</option>
                        @foreach ($registers as $register)
                            <option value="{{ $register->id }}">{{ $register->code }} — {{ $register->storeMarket->store->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label">Opening Float</label>
                    <input type="text" wire:model="openingFloat" class="input input-bordered w-full" />
                </div>
                <x-ui.button type="submit" variant="primary">Open Register</x-ui.button>
            </form>
        </x-ui.card>
    @endif
</div>
