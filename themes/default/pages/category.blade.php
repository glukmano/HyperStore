<div class="space-y-6">
    <x-ui.breadcrumbs :items="['Home' => route('storefront.home'), $category?->translation()?->name ?? 'Category' => null]" />

    <h1 class="text-2xl font-bold">{{ $category?->translation()?->name ?? __('Category not found') }}</h1>

    @if($category === null)
        <x-ui.empty-state message="{{ __('This category could not be found.') }}" />
    @elseif($paginator && $paginator->isNotEmpty())
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            @foreach($paginator as $product)
                <x-theme::product-card :product="$product" />
            @endforeach
        </div>
        <x-ui.pagination :paginator="$paginator" />
    @else
        <x-ui.empty-state message="{{ __('No products in this category yet.') }}" />
    @endif
</div>
