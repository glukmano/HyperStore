<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\Demo\DemoSeeder;
use Illuminate\Console\Command;

/**
 * Pre-Production Readiness — Demo Dataset entry point.
 *
 * Owner Delta (Demo Data Safety): explicitly separate from `db:seed`.
 * Refuses to run against a production environment unless an explicit,
 * intentionally-designed override flag is passed — there is no implicit
 * production allowance.
 */
class DemoSeedCommand extends Command
{
    protected $signature = 'demo:seed {--force-in-production : Explicit override required to run this in a production environment}';

    protected $description = 'Seed a compact, coherent Demo Dataset for local exploration (Tenant/Store/Catalog/representative business data) — LOCAL/DEMO USE ONLY';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force-in-production')) {
            $this->error('Refusing to seed demo data in a production environment. Pass --force-in-production if you genuinely intend this (not recommended).');

            return self::FAILURE;
        }

        $this->warn('Seeding the Demo Dataset (local/demo credentials, predictable passwords — never use in a real deployment).');

        app(DemoSeeder::class)->run();

        $this->newLine();
        $this->info('Demo Dataset ready. Local credentials (password: "password" for all):');
        $this->table(['Role', 'Email'], [
            ['Store Owner', 'owner@demo.hyperstore.test'],
            ['Cashier', 'cashier@demo.hyperstore.test'],
            ['Customer', 'customer@demo.hyperstore.test'],
            ['Vendor Staff', 'vendor@demo.hyperstore.test'],
            ['B2B Buyer', 'b2b-buyer@demo.hyperstore.test'],
            ['B2B Approver', 'b2b-approver@demo.hyperstore.test'],
        ]);
        $this->line('Platform Super Admin (seeded by the normal db:seed): admin@hyperstore.test / password');

        return self::SUCCESS;
    }
}
