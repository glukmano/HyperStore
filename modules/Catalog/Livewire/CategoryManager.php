<?php

declare(strict_types=1);

namespace Modules\Catalog\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Catalog\Actions\CreateCategoryAction;
use Modules\Catalog\DTOs\CategoryData;
use Modules\Catalog\Models\Category;

class CategoryManager extends Component
{
    public string $code = '';

    public string $name = '';

    public string $slug = '';

    public ?int $parentId = null;

    public function createCategory(): void
    {
        $this->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
        ]);

        $tenantId = app(ContextManager::class)->getTenant()->getId() ?? 1;

        $data = new CategoryData(
            tenantId: (int) $tenantId,
            code: $this->code,
            translations: [
                app()->getLocale() => ['name' => $this->name, 'slug' => $this->slug],
            ],
            parentId: $this->parentId,
        );

        app(CreateCategoryAction::class)->execute($data);

        $this->reset(['code', 'name', 'slug', 'parentId']);
        session()->flash('success', 'Category created.');
    }

    public function render(): View|Factory
    {
        $tenantId = app(ContextManager::class)->getTenant()->getId() ?? 1;

        return view('catalog::livewire.category-manager', [
            'categories' => Category::where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->with(['translations', 'children.translations'])
                ->whereNull('parent_id')
                ->get(),
            'allCategories' => Category::where('tenant_id', $tenantId)->where('status', 'active')->get(),
        ])->layout('layouts.control-center', ['title' => 'Category Hierarchy']);
    }
}
