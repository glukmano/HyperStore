@php
    $navContext = request()->is('control-center/super-admin*') ? 'super-admin'
        : (app(\App\Core\Context\ContextManager::class)->hasVendor() ? 'vendor' : 'tenant');

    $navGroups = app(\App\Core\Navigation\Contracts\NavigationRegistryInterface::class)
        ->visibleGrouped(auth()->user(), $navContext);
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app(\App\Core\Localization\Contracts\LocaleManagerInterface::class)->isRtl() ? 'rtl' : 'ltr' }}" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Control Center' }} — HyperStore</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-base-200">

<div class="drawer lg:drawer-open">
    <input id="cc-drawer" type="checkbox" class="drawer-toggle" />

    <div class="drawer-content flex flex-col min-h-screen">
        {{-- Top Navigation Bar --}}
        <div class="navbar bg-base-100 shadow-sm sticky top-0 z-40">
            <div class="navbar-start">
                <label for="cc-drawer" class="btn btn-ghost btn-sm lg:hidden">☰</label>
                <span class="text-xl font-bold tracking-tight ps-2">
                    ⚡ HyperStore
                    <x-ui.badge variant="ghost" class="ms-2 text-xs">Control Center</x-ui.badge>
                </span>
            </div>

            <div class="navbar-end gap-2 pe-4">
                <a href="{{ request()->fullUrlWithQuery(['lang' => app()->getLocale() === 'ar' ? 'en' : 'ar']) }}"
                   class="btn btn-ghost btn-sm">
                    {{ app()->getLocale() === 'ar' ? '🌐 EN' : '🌐 AR' }}
                </a>
                @auth
                    <x-ui.dropdown label="{{ auth()->user()->name }}">
                        @if(\Illuminate\Support\Facades\Route::has('logout'))
                            <li><form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">{{ __('Log out') }}</button></form></li>
                        @else
                            <li><span class="opacity-50">{{ auth()->user()->email }}</span></li>
                        @endif
                    </x-ui.dropdown>
                @endauth
            </div>
        </div>

        {{-- Main Content --}}
        <main class="container mx-auto p-6 flex-1">
            {{ $slot }}
        </main>

        <footer class="footer footer-center p-4 text-base-content text-xs opacity-50">
            <p>HyperStore Platform · {{ now()->format('Y') }}</p>
        </footer>
    </div>

    <div class="drawer-side z-50">
        <label for="cc-drawer" aria-label="close sidebar" class="drawer-overlay"></label>
        <aside class="bg-base-100 min-h-full w-64 p-4 border-e border-base-300">
            <nav>
                @forelse($navGroups as $group => $items)
                    <div class="mb-4">
                        <p class="text-xs font-semibold uppercase text-base-content/40 px-2 mb-1">{{ $group }}</p>
                        <ul class="menu menu-sm p-0">
                            @foreach($items as $item)
                                <li>
                                    <a href="{{ $item->url() }}" wire:navigate class="{{ request()->routeIs($item->routeName) ? 'active' : '' }}">
                                        @if($item->icon) <span>{{ $item->icon }}</span> @endif
                                        {{ $item->label }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @empty
                    <p class="text-sm text-base-content/50 px-2">{{ __('No navigation items available.') }}</p>
                @endforelse
            </nav>
        </aside>
    </div>
</div>

<x-ui.toast />

@livewireScripts
</body>
</html>
