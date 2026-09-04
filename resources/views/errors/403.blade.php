<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app(\App\Core\Localization\Contracts\LocaleManagerInterface::class)->isRtl() ? 'rtl' : 'ltr' }}" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Access Denied') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-base-200 flex items-center justify-center p-6">
    <x-ui.card class="max-w-md w-full text-center">
        <h1 class="text-2xl font-bold mb-2">{{ __('Access Denied') }}</h1>
        <p class="text-base-content/60 mb-6">{{ ($exception ?? null)?->getMessage() ?: __("You don't have permission to view this page.") }}</p>
        <a href="{{ \Illuminate\Support\Facades\Route::has('control-center.dashboard') ? route('control-center.dashboard') : url('/') }}">
            <x-ui.button variant="primary">{{ __('Back') }}</x-ui.button>
        </a>
    </x-ui.card>
</body>
</html>
