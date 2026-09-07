<div class="grid gap-6 lg:grid-cols-4">
    <div class="lg:col-span-1 space-y-4">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Developer Center</h1>

        @foreach ($sections as $sectionKey => $slugs)
            <x-ui.card :title="ucfirst($sectionKey)">
                <ul class="menu p-0">
                    @forelse ($slugs as $slugItem)
                        <li>
                            <a href="{{ route('control-center.platform.developer.docs.show', ['section' => $sectionKey, 'slug' => $slugItem]) }}"
                               class="{{ $section === $sectionKey && $slug === $slugItem ? 'active' : '' }}">
                                {{ $slugItem }}
                            </a>
                        </li>
                    @empty
                        <li class="text-sm opacity-60 px-2">No documents.</li>
                    @endforelse
                </ul>
            </x-ui.card>
        @endforeach
    </div>

    <div class="lg:col-span-3">
        @if ($errorMessage)
            <x-ui.alert variant="error">{{ $errorMessage }}</x-ui.alert>
        @elseif ($document)
            <x-ui.card :title="$document->title">
                <article class="prose max-w-none">
                    {!! $document->safeHtml !!}
                </article>
            </x-ui.card>
        @else
            <x-ui.card title="Welcome">
                <p>Select a document from the left to view authoritative repository documentation — Theme SDK, Plugin SDK, module references, architecture decisions, phase history, and QA/readiness reports.</p>
                <p class="mt-4 text-sm opacity-70">Documentation is rendered directly from the repository's <code>docs/</code> directory — nothing is duplicated here.</p>
            </x-ui.card>
        @endif
    </div>
</div>
