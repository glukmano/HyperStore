<section class="grid grid-cols-2 md:grid-cols-3 gap-4 py-4">
    @foreach (($config['image_urls'] ?? []) as $url)
        <img src="{{ $url }}" alt="" class="w-full h-48 object-cover rounded-box">
    @endforeach
</section>
