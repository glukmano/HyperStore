<?php

declare(strict_types=1);

use Modules\Catalog\Contracts\VariantCombinatorInterface;

test('generates cartesian product of option combinations', function (): void {
    $combinator = app(VariantCombinatorInterface::class);

    $matrix = [
        1 => [101, 102], // Color: Red, Blue
        2 => [201, 202, 203], // Size: S, M, L
    ];

    $combinations = $combinator->generateCombinations($matrix);

    expect($combinations)->toHaveCount(6)
        ->and($combinations[0])->toBe([1 => 101, 2 => 201])
        ->and($combinations[5])->toBe([1 => 102, 2 => 203]);
});

test('combination hash is order independent', function (): void {
    $combinator = app(VariantCombinatorInterface::class);

    $hashA = $combinator->computeCombinationHash([1 => 101, 2 => 202]);
    $hashB = $combinator->computeCombinationHash([2 => 202, 1 => 101]);

    expect($hashA)->toBe($hashB)
        ->and(strlen($hashA))->toBe(64);
});
