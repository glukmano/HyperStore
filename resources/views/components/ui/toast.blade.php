{{-- Session-flash-driven toast. Include once in the shell layout. --}}
@if(session()->has('success') || session()->has('error') || session()->has('warning') || session()->has('info'))
    <div class="toast toast-top toast-end z-[100]" x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)">
        @if(session()->has('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif
        @if(session()->has('error'))
            <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
        @endif
        @if(session()->has('warning'))
            <x-ui.alert variant="warning">{{ session('warning') }}</x-ui.alert>
        @endif
        @if(session()->has('info'))
            <x-ui.alert variant="info">{{ session('info') }}</x-ui.alert>
        @endif
    </div>
@endif
