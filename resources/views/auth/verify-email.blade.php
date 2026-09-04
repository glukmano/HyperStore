<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app(\App\Core\Localization\Contracts\LocaleManagerInterface::class)->isRtl() ? 'rtl' : 'ltr' }}" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('Verify Email') }} — HyperStore</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-base-200 flex items-center justify-center p-6">

    <x-ui.card class="w-full max-w-sm text-center">
        <div class="mb-6">
            <span class="text-xl font-bold tracking-tight">⚡ HyperStore</span>
        </div>

        <p class="text-sm text-base-content/70 mb-4">
            {{ __('Thanks for signing up! Before getting started, please verify your email address by clicking the link we just emailed to you.') }}
        </p>

        @if (session('status') === 'verification-link-sent')
            <x-ui.alert variant="success" class="mb-4">
                {{ __('A new verification link has been sent to your email address.') }}
            </x-ui.alert>
        @endif

        <form method="POST" action="{{ route('verification.send') }}" class="space-y-3">
            @csrf
            <x-ui.button type="submit" variant="primary" class="w-full">{{ __('Resend Verification Email') }}</x-ui.button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="mt-3">
            @csrf
            <button type="submit" class="link link-neutral text-sm">{{ __('Log out') }}</button>
        </form>
    </x-ui.card>

</body>
</html>
