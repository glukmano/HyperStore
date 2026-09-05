<div>
    @if(count($availableLocales) > 1 || count($availableCurrencies) > 1 || count($availableMarketCodes) > 1)
        <div class="flex items-center gap-2">
            @if(count($availableMarketCodes) > 1)
                <div class="w-28">
                    <x-ui.select wire:model.live="marketCode">
                        @foreach($availableMarketCodes as $code)
                            <option value="{{ $code }}">{{ $code }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
            @endif

            @if(count($availableLocales) > 1)
                <div class="w-28">
                    <x-ui.select wire:model.live="locale">
                        @foreach($availableLocales as $code)
                            <option value="{{ $code }}">{{ $code }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
            @endif

            @if(count($availableCurrencies) > 1)
                <div class="w-24">
                    <x-ui.select wire:model.live="currency">
                        @foreach($availableCurrencies as $code)
                            <option value="{{ $code }}">{{ $code }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
            @endif
        </div>
    @endif
</div>
