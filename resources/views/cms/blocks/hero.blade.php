<section class="hero bg-base-200 rounded-box py-16 px-8 text-center">
    <div class="max-w-2xl mx-auto">
        @if (! empty($config['heading']))
            <h1 class="text-4xl font-bold">{{ $config['heading'] }}</h1>
        @endif
        @if (! empty($config['subheading']))
            <p class="py-4 text-base-content/70">{{ $config['subheading'] }}</p>
        @endif
        @if (! empty($config['cta_text']) && ! empty($config['cta_url']))
            <a href="{{ $config['cta_url'] }}" class="btn btn-primary">{{ $config['cta_text'] }}</a>
        @endif
    </div>
</section>
