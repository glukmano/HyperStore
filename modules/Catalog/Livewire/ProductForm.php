<?php

declare(strict_types=1);

namespace Modules\Catalog\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\Actions\UpdateProductAction;
use Modules\Catalog\Contracts\ProductTypeRegistryInterface;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Models\Brand;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;

class ProductForm extends Component
{
    public ?int $productId = null;

    public string $sku = '';

    public string $name = '';

    public string $productType = 'physical';

    public ?int $brandId = null;

    public string $status = 'draft';

    /** @var array<int, int> */
    public array $categoryIds = [];

    public function mount(?int $id = null): void
    {
        if ($id !== null) {
            $product = Product::with(['translations', 'categories'])->findOrFail($id);
            $this->productId = $product->id;
            $this->sku = $product->sku;
            $trans = $product->translation();
            $this->name = $trans !== null ? $trans->name : '';
            $this->productType = $product->product_type;
            $this->brandId = $product->brand_id;
            $this->status = $product->status;
            $this->categoryIds = $product->categories->pluck('id')->all();
        }
    }

    public function save(): void
    {
        $this->validate([
            'sku' => ['required', 'string', 'max:150'],
            'name' => ['required', 'string', 'max:255'],
            'productType' => ['required', 'string'],
        ]);

        $tenantId = app(ContextManager::class)->getTenant()->getId() ?? 1;

        $data = new ProductData(
            tenantId: (int) $tenantId,
            productType: $this->productType,
            sku: $this->sku,
            translations: [
                app()->getLocale() => ['name' => $this->name],
            ],
            brandId: $this->brandId,
            status: $this->status,
            categoryIds: $this->categoryIds,
        );

        if ($this->productId !== null) {
            $product = Product::findOrFail($this->productId);
            app(UpdateProductAction::class)->execute($product, $data);
            session()->flash('success', 'Product updated successfully.');
        } else {
            app(CreateProductAction::class)->execute($data);
            session()->flash('success', 'Product created successfully.');
            $this->redirectRoute('control-center.catalog.products.index');
        }
    }

    public function render(): View|Factory
    {
        $tenantId = app(ContextManager::class)->getTenant()->getId() ?? 1;

        return view('catalog::livewire.product-form', [
            'brands' => Brand::where('tenant_id', $tenantId)->where('status', 'active')->get(),
            'categories' => Category::where('tenant_id', $tenantId)->where('status', 'active')->get(),
            'productTypes' => app(ProductTypeRegistryInterface::class)->all(),
        ])->layout('layouts.control-center', ['title' => $this->productId ? 'Edit Product' : 'Create Product']);
    }
}
