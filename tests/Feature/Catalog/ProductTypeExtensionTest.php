<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use InvalidArgumentException;
use Modules\Catalog\Contracts\ProductTypeDefinition;
use Modules\Catalog\Contracts\ProductTypeRegistryInterface;
use Modules\Catalog\ProductTypes\PhysicalProductType;

test('allows registering custom product types at runtime', function (): void {
    $registry = app(ProductTypeRegistryInterface::class);

    $customType = new class extends ProductTypeDefinition
    {
        public function getId(): string
        {
            return 'custom-3d-print';
        }

        public function getName(): string
        {
            return '3D Print Model';
        }

        public function getDescription(): string
        {
            return 'Custom on-demand 3D print item.';
        }

        public function requiresShipping(): bool
        {
            return true;
        }
    };

    $registry->register($customType);

    expect($registry->has('custom-3d-print'))->toBeTrue()
        ->and($registry->get('custom-3d-print')->getName())->toBe('3D Print Model')
        ->and($registry->get('custom-3d-print')->requiresShipping())->toBeTrue();
});

test('duplicate product type registration throws InvalidArgumentException', function (): void {
    $registry = app(ProductTypeRegistryInterface::class);

    expect(fn () => $registry->register(new PhysicalProductType))
        ->toThrow(InvalidArgumentException::class, 'Product type [physical] is already registered.');
});
