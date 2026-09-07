@props(['banner', 'variant' => 'hero'])

@php
    $translation = $banner->translation();
    $imageUrl = $banner->getFirstMediaUrl('image') ?: null;
    $isHero = $variant === 'hero';
@endphp

@if ($translation)
    <section class="{{ $isHero ? 'hero bg-base-200' : 'bg-primary text-primary-content' }} rounded-box overflow-hidden">
        <div class="{{ $isHero ? 'hero-content flex-col lg:flex-row gap-8 py-16' : 'flex flex-col md:flex-row items-center gap-6 p-8' }}">
            @if ($imageUrl)
                <img src="{{ $imageUrl }}" alt="{{ $translation->headline }}" class="{{ $isHero ? 'max-w-sm rounded-box shadow-lg' : 'w-full md:w-40 rounded-box' }} object-cover" loading="lazy" />
            @endif
            <div class="text-center {{ $isHero ? 'lg:text-start max-w-md' : 'md:text-start flex-1' }}">
                @if ($translation->headline)
                    <h2 class="{{ $isHero ? 'text-4xl font-bold' : 'text-xl font-bold' }}">{{ $translation->headline }}</h2>
                @endif
                @if ($translation->cta_text && $translation->link_url)
                    <a href="{{ $translation->link_url }}" wire:navigate class="btn {{ $isHero ? 'btn-primary mt-6' : 'btn-secondary mt-4' }}">
                        {{ $translation->cta_text }}
                    </a>
                @endif
            </div>
        </div>
    </section>
@endif
