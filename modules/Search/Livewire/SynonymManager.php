<?php

declare(strict_types=1);

namespace Modules\Search\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Search\Models\SearchSynonym;

class SynonymManager extends Component
{
    public string $locale = 'en';

    public string $term = '';

    public string $synonymsInput = '';

    public function create(): void
    {
        $this->authorizeManage();

        $this->validate([
            'term' => 'required|string|max:100',
            'synonymsInput' => 'required|string',
        ]);

        $synonyms = array_values(array_filter(array_map('trim', explode(',', $this->synonymsInput))));

        SearchSynonym::query()->updateOrCreate(
            ['tenant_id' => $this->tenantId(), 'locale' => $this->locale, 'term' => mb_strtolower(trim($this->term))],
            ['synonyms' => $synonyms],
        );

        $this->reset(['term', 'synonymsInput']);
        session()->flash('success', 'Synonym rule saved — the next full reindex will restore it into Meilisearch.');
    }

    public function delete(int $synonymId): void
    {
        $this->authorizeManage();
        SearchSynonym::query()->where('tenant_id', $this->tenantId())->where('id', $synonymId)->delete();
    }

    public function render(): View|Factory
    {
        $this->authorizeManage();

        $synonyms = SearchSynonym::query()->where('tenant_id', $this->tenantId())->latest()->paginate(20);

        return view('livewire.control-center.search.synonym-manager', ['synonyms' => $synonyms])
            ->layout('layouts.control-center', ['title' => 'Search Synonyms']);
    }

    private function tenantId(): int
    {
        return (int) app(ContextManager::class)->getTenant()->getId();
    }

    private function authorizeManage(): void
    {
        if (! auth()->user()?->can('search.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }
}
