<?php

declare(strict_types=1);

use Modules\Catalog\Contracts\ProductTypeRegistryInterface;
use Modules\Catalog\ProductTypes\DigitalProductType;
use Modules\Catalog\ProductTypes\NullProductType;
use Modules\Catalog\ProductTypes\PhysicalProductType;

test('registry contains all 22 first-party product types', function (): void {
    $registry = app(ProductTypeRegistryInterface::class);
    $all = $registry->all();

    expect($all)->toBeArray()
        ->and(count($all))->toBe(22)
        ->and($registry->has('physical'))->toBeTrue()
        ->and($registry->has('digital'))->toBeTrue()
        ->and($registry->has('license'))->toBeTrue()
        ->and($registry->has('subscription'))->toBeTrue()
        ->and($registry->has('bundle'))->toBeTrue()
        ->and($registry->has('variable'))->toBeTrue()
        ->and($registry->has('auction'))->toBeTrue()
        ->and($registry->has('rfq'))->toBeTrue();
});

test('physical product type declares correct capabilities', function (): void {
    $type = new PhysicalProductType;

    expect($type->getId())->toBe('physical')
        ->and($type->requiresShipping())->toBeTrue()
        ->and($type->supportsInventory())->toBeTrue()
        ->and($type->supportsVariants())->toBeTrue()
        ->and($type->supportsDownloads())->toBeFalse()
        ->and($type->getCapabilities()['requiresShipping'])->toBeTrue();
});

test('digital product type declares correct capabilities', function (): void {
    $type = new DigitalProductType;

    expect($type->getId())->toBe('digital')
        ->and($type->requiresShipping())->toBeFalse()
        ->and($type->supportsDownloads())->toBeTrue();
});

test('unknown product type returns NullProductType fallback', function (): void {
    $registry = app(ProductTypeRegistryInterface::class);
    $unknown = $registry->get('non_existent_type');

    expect($unknown)->toBeInstanceOf(NullProductType::class)
        ->and($unknown->getId())->toBe('non_existent_type')
        ->and($unknown->requiresShipping())->toBeFalse();
});
