<?php

declare(strict_types=1);

namespace App\Core\Theme\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Pre-Production Readiness: a genuinely minimal scaffold generator,
 * mirroring the real shape documented in docs/themes/developer-guide.md
 * (a `theme.json` manifest + the minimum viable `layouts/app.blade.php`
 * and `pages/home.blade.php`). Everything else falls through to the
 * `default` theme via the real inheritance chain (`extends: "default"`).
 */
class ThemeMakeCommand extends Command
{
    protected $signature = 'theme:make {name : The theme name, kebab-case (e.g. my-theme)}';

    protected $description = 'Scaffold a minimal theme that extends the default theme';

    public function handle(): int
    {
        $name = Str::kebab((string) $this->argument('name'));
        $path = base_path("themes/{$name}");

        if (is_dir($path)) {
            $this->error("themes/{$name} already exists.");

            return self::FAILURE;
        }

        mkdir($path.'/layouts', 0755, true);
        mkdir($path.'/pages', 0755, true);

        file_put_contents($path.'/theme.json', json_encode([
            'name' => $name,
            'version' => '1.0.0',
            'extends' => 'default',
            'description' => 'Describe this theme.',
            'supported_product_type_templates' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        file_put_contents($path.'/layouts/app.blade.php', <<<'BLADE'
        <!doctype html>
        <html lang="{{ app()->getLocale() }}" dir="{{ str(app()->getLocale())->is('ar') ? 'rtl' : 'ltr' }}">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>@yield('title', config('app.name'))</title>
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        </head>
        <body>
            {{ $slot ?? '' }}
        </body>
        </html>
        BLADE);

        file_put_contents($path.'/pages/home.blade.php', <<<'BLADE'
        <x-dynamic-component :component="'layouts.app'">
            <main class="container mx-auto px-4 py-8">
                <h1 class="text-2xl font-bold">Welcome</h1>
            </main>
        </x-dynamic-component>
        BLADE);

        $this->info("Created themes/{$name} (extends: default).");
        $this->line('See docs/themes/developer-guide.md for the full directory structure (components/, sections/product-types/, more pages/).');
        $this->line('Activate it for a Store via Control Center → Platform → Stores → (select Store) → Theme.');

        return self::SUCCESS;
    }
}
