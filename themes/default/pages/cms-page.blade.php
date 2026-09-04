<div class="space-y-6">
    <x-ui.breadcrumbs :items="['Home' => route('storefront.home'), $translation->title => null]" />

    <h1 class="text-2xl font-bold">{{ $translation->title }}</h1>

    <div class="space-y-4">
        @foreach ($renderedBlocks as $renderedBlock)
            {{ $renderedBlock }}
        @endforeach
    </div>
</div>
