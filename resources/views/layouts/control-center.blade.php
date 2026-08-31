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

    {{-- Top Navigation Bar --}}
    <div class="navbar bg-base-100 shadow-sm sticky top-0 z-50">
        <div class="navbar-start">
            <span class="text-xl font-bold tracking-tight px-4">
                ⚡ HyperStore
                <x-ui.badge variant="ghost" class="ms-2 text-xs">Control Center</x-ui.badge>
            </span>
        </div>

        <div class="navbar-end gap-2 px-4">
            {{-- RTL toggle for Phase 01 validation --}}
            <a href="{{ request()->fullUrlWithQuery(['lang' => app()->getLocale() === 'ar' ? 'en' : 'ar']) }}"
               class="btn btn-ghost btn-sm">
                {{ app()->getLocale() === 'ar' ? '🌐 EN' : '🌐 AR' }}
            </a>

            <div class="badge badge-outline">PHP {{ PHP_MAJOR_VERSION }}.{{ PHP_MINOR_VERSION }}</div>
            <div class="badge badge-outline badge-success">Laravel {{ app()->version() }}</div>
        </div>
    </div>

    {{-- Main Content --}}
    <main class="container mx-auto p-6">
        {{ $slot }}
    </main>

    {{-- Footer --}}
    <footer class="footer footer-center p-4 text-base-content text-xs opacity-50">
        <p>HyperStore Platform · Phase 01 · {{ now()->format('Y') }}</p>
    </footer>

    @livewireScripts
</body>
</html>
