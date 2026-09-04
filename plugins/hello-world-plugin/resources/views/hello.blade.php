<div class="space-y-6">
    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), __('hello-world-plugin::hello.title') => null]" />

    <x-ui.card :title="__('hello-world-plugin::hello.title')">
        <p class="text-base-content/70">{{ __('hello-world-plugin::hello.message') }}</p>
    </x-ui.card>
</div>
