<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app(\App\Core\Localization\Contracts\LocaleManagerInterface::class)->isRtl() ? 'rtl' : 'ltr' }}" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('Reset Password') }} — HyperStore</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-base-200 flex items-center justify-center p-6">

    <x-ui.card class="w-full max-w-sm">
        <div class="text-center mb-6">
            <span class="text-xl font-bold tracking-tight">⚡ HyperStore</span>
            <p class="text-sm text-base-content/60 mt-1">{{ __('Choose a new password') }}</p>
        </div>

        @if ($errors->any())
            <x-ui.alert variant="error" class="mb-4">
                {{ $errors->first() }}
            </x-ui.alert>
        @endif

        <form method="POST" action="{{ route('password.store') }}" class="space-y-4">
            @csrf

            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <x-ui.input type="email" name="email" label="{{ __('Email') }}" value="{{ old('email', $request->query('email')) }}" required autofocus autocomplete="username" />

            <x-ui.input type="password" name="password" label="{{ __('New Password') }}" required autocomplete="new-password" />

            <x-ui.input type="password" name="password_confirmation" label="{{ __('Confirm New Password') }}" required autocomplete="new-password" />

            <x-ui.button type="submit" variant="primary" class="w-full">{{ __('Reset Password') }}</x-ui.button>
        </form>
    </x-ui.card>

</body>
</html>
