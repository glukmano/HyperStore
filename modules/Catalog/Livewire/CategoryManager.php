<?php

declare(strict_types=1);

namespace Modules\Catalog\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Component;
use Modules\Catalog\Actions\CreateCategoryAction;
use Modules\Catalog\Contracts\CategoryHierarchyValidatorInterface;
use Modules\Catalog\DTOs\CategoryData;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\CategoryTranslation;

class CategoryManager extends Component
{
    public string $code = '';

    public string $name = '';

    public string $slug = '';

    public ?int $parentId = null;

    public ?int $editingId = null;

    public string $editCode = '';

    public string $editName = '';

    public string $editSlug = '';

    public ?int $editParentId = null;

    public ?int $confirmArchiveId = null;

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

    public function editCategory(int $id): void
    {
        if (! auth()->user()?->can('categories.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = app(ContextManager::class)->getTenant()->getId() ?? 1;

        $category = Category::where('tenant_id', $tenantId)->findOrFail($id);
        $trans = $category->translation();

        $this->editingId = $category->id;
        $this->editCode = $category->code;
        $this->editName = $trans !== null ? $trans->name : '';
        $this->editSlug = $trans !== null ? $trans->slug : '';
        $this->editParentId = $category->parent_id;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editCode', 'editName', 'editSlug', 'editParentId']);
    }

    public function updateCategory(): void
    {
        if (! auth()->user()?->can('categories.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        if ($this->editingId === null) {
            return;
        }

        $this->validate([
            'editCode' => ['required', 'string', 'max:100'],
            'editName' => ['required', 'string', 'max:255'],
            'editSlug' => ['required', 'string', 'max:255'],
        ]);

        $tenantId = app(ContextManager::class)->getTenant()->getId() ?? 1;

        $category = Category::where('tenant_id', $tenantId)->findOrFail($this->editingId);

        try {
            app(CategoryHierarchyValidatorInterface::class)->assertNoCycle($category, $this->editParentId);
        } catch (InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $category->update([
            'code' => $this->editCode,
            'parent_id' => $this->editParentId,
        ]);

        CategoryTranslation::updateOrCreate(
            ['category_id' => $category->id, 'locale' => app()->getLocale()],
            ['name' => $this->editName, 'slug' => $this->editSlug]
        );

        $this->reset(['editingId', 'editCode', 'editName', 'editSlug', 'editParentId']);
        session()->flash('success', 'Category updated.');
    }

    public function openArchiveConfirm(int $id): void
    {
        $this->confirmArchiveId = $id;
    }

    public function cancelArchiveConfirm(): void
    {
        $this->confirmArchiveId = null;
    }

    public function archiveCategory(): void
    {
        if (! auth()->user()?->can('categories.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        if ($this->confirmArchiveId === null) {
            return;
        }

        $tenantId = app(ContextManager::class)->getTenant()->getId() ?? 1;

        $category = Category::where('tenant_id', $tenantId)->findOrFail($this->confirmArchiveId);
        $category->update(['status' => 'archived']);

        $this->confirmArchiveId = null;
        session()->flash('success', 'Category archived.');
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
