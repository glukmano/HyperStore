<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;

/**
 * Pre-Production Readiness — the single entry point for the Demo Dataset.
 * Invoked ONLY via `php artisan demo:seed` (never from the normal
 * `db:seed`/`DatabaseSeeder::run()` path) — see App\Console\Commands\
 * DemoSeedCommand for the production safety guard.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoFoundationSeeder::class,
            DemoCatalogSeeder::class,
            DemoBusinessDataSeeder::class,
        ]);
    }
}
