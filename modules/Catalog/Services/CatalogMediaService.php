<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use Illuminate\Http\UploadedFile;
use Modules\Catalog\Models\Brand;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class CatalogMediaService
{
    public function attachProductThumbnail(Product $product, UploadedFile|string $file): Media
    {
        if (is_string($file)) {
            return $product->addMedia($file)->toMediaCollection('product_thumbnail');
        }

        return $product->addMediaFromRequest('thumbnail')->toMediaCollection('product_thumbnail');
    }

    public function attachProductGalleryImage(Product $product, UploadedFile|string $file): Media
    {
        if (is_string($file)) {
            return $product->addMedia($file)->toMediaCollection('product_gallery');
        }

        return $product->addMedia($file)->toMediaCollection('product_gallery');
    }

    public function attachVariantGalleryImage(ProductVariant $variant, UploadedFile|string $file): Media
    {
        if (is_string($file)) {
            return $variant->addMedia($file)->toMediaCollection('variant_gallery');
        }

        return $variant->addMedia($file)->toMediaCollection('variant_gallery');
    }

    public function attachCategoryImage(Category $category, UploadedFile|string $file): Media
    {
        if (is_string($file)) {
            return $category->addMedia($file)->toMediaCollection('category_image');
        }

        return $category->addMedia($file)->toMediaCollection('category_image');
    }

    public function attachBrandLogo(Brand $brand, UploadedFile|string $file): Media
    {
        if (is_string($file)) {
            return $brand->addMedia($file)->toMediaCollection('brand_logo');
        }

        return $brand->addMedia($file)->toMediaCollection('brand_logo');
    }
}
