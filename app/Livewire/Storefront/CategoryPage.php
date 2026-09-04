<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;

class CategoryPage extends Component
{
    use WithPagination;

    public string $code;

    public function mount(string $code): void
    {
        $this->code = $code;
    }

    public function render(): View
    {
        $tenantId = app(ContextManager::class)->getTenant()->getId();

        $category = $tenantId !== null
            ? Category::query()->where('tenant_id', $tenantId)->where('code', $this->code)->with('translations')->first()
            : null;

        $products = collect();
        $paginator = null;

        if ($category !== null) {
            $paginator = Product::query()
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->whereHas('categories', fn ($q) => $q->where('categories.id', $category->id))
                ->with('translations')
                ->paginate(24, pageName: 'page');
        }

        $title = 'Category';
        if ($category !== null) {
            $translation = $category->translation();
            if ($translation !== null) {
                $title = $translation->name;
            }
        }

        return view('theme::pages.category', [
            'category' => $category,
            'paginator' => $paginator,
        ])->layout('theme::layouts.app', ['title' => $title]);
    }
}
