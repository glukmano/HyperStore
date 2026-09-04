<?php

declare(strict_types=1);

/**
 * Static-scan test (Phase-15 §14/§19): asserts no physical-direction Tailwind utility
 * class appears in the shared `<x-ui.*>` component library or the `themes/` tree, so
 * every reusable UI primitive and every theme page/section stays RTL/LTR-safe by
 * construction rather than by convention alone.
 */
test('no physical-direction Tailwind utility classes exist in the ui component library or themes tree', function (): void {
    $scanDirs = [
        base_path('resources/views/components/ui'),
        base_path('themes'),
    ];

    // Matches ml-, mr-, pl-, pr-, left-, right-, text-left, text-right as whole
    // Tailwind class tokens (word-boundary guarded so e.g. "wire:model" or "url-" never matches).
    $forbiddenPattern = '/(^|["\'\s])(ml|mr|pl|pr|left|right)-[a-z0-9\/\.\[\]%_-]+|(^|["\'\s])text-(left|right)(?=["\'\s]|$)/i';

    $violations = [];

    foreach ($scanDirs as $dir) {
        if (! is_dir($dir)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! str_ends_with((string) $file->getFilename(), '.blade.php')) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            if (preg_match_all($forbiddenPattern, $contents, $matches)) {
                foreach (array_unique($matches[0]) as $match) {
                    $violations[] = trim($file->getPathname().' :: '.$match);
                }
            }
        }
    }

    expect($violations)->toBe([]);
})->skip(fn (): bool => ! is_dir(base_path('themes')), 'themes/ directory not present');
