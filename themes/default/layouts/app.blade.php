<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app(\App\Core\Localization\Contracts\LocaleManagerInterface::class)->isRtl() ? 'rtl' : 'ltr' }}" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Store' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-base-200 flex flex-col">

    <div class="navbar bg-base-100 shadow-sm sticky top-0 z-50">
        <div class="navbar-start">
            <a href="{{ route('storefront.home') }}" wire:navigate class="text-xl font-bold tracking-tight ps-4">
                {{ $storeName ?? 'HyperStore' }}
            </a>
        </div>

        <div class="navbar-center hidden md:flex">
            {{-- Search entry point intentionally disabled: Owner Delta §0.4 — no interim
                 query logic. Ships inert until a real Search phase exists. --}}
            <label class="input input-bordered flex items-center gap-2 opacity-50 pointer-events-none" aria-disabled="true">
                <input type="text" disabled placeholder="{{ __('Search (coming soon)') }}" class="grow" />
            </label>
        </div>

        <div class="navbar-end gap-2 pe-4">
            <a href="{{ route('storefront.cart') }}" wire:navigate class="btn btn-ghost btn-sm">
                🛒 <x-ui.badge variant="neutral" class="ms-1">{{ $cartItemCount ?? 0 }}</x-ui.badge>
            </a>
            <a href="{{ route('storefront.order-lookup') }}" wire:navigate class="btn btn-ghost btn-sm">
                {{ __('Track Order') }}
            </a>
        </div>
    </div>

    <main class="container mx-auto p-6 flex-1">
        {{ $slot }}
    </main>

    <footer class="footer footer-center p-6 text-base-content/60 text-xs border-t border-base-300">
        <p>{{ $storeName ?? 'HyperStore' }} · {{ now()->format('Y') }}</p>
    </footer>

    <x-ui.toast />

    @livewireScripts
</body>
</html>
