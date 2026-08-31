<?php

declare(strict_types=1);

use Modules\Catalog\Contracts\CategoryHierarchyValidatorInterface;
use Modules\Catalog\Models\Category;

test('category cannot be its own parent', function (): void {
    $validator = app(CategoryHierarchyValidatorInterface::class);

    $cat = new Category(['tenant_id' => 1]);
    $cat->id = 10;

    expect(fn () => $validator->assertNoCycle($cat, 10))
        ->toThrow(InvalidArgumentException::class, 'Category cannot be its own parent.');
});

test('valid hierarchy succeeds without exception', function (): void {
    $validator = app(CategoryHierarchyValidatorInterface::class);

    $cat = new Category(['tenant_id' => 1]);
    $cat->id = 10;

    expect(fn () => $validator->assertNoCycle($cat, null))->not->toThrow(Exception::class);
});
