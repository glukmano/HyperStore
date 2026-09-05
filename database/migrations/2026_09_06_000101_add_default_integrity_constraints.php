<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase-18 Owner Delta §14: at-most-one-default invariants enforced by
 * PostgreSQL partial unique indexes, not application-only check-then-write
 * logic — closes a pre-existing integrity gap (store_markets never had a
 * one-default-per-store guarantee at all) and makes the rest race-proof.
 * Postgres-only DDL, matching this codebase's established convention of
 * guarding raw partial-index SQL with a driver check.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE UNIQUE INDEX languages_one_global_default ON languages ((is_default)) WHERE is_default = true');
        DB::statement('CREATE UNIQUE INDEX currencies_one_global_default ON currencies ((is_default)) WHERE is_default = true');
        DB::statement('CREATE UNIQUE INDEX market_languages_one_default_per_market ON market_languages (market_id) WHERE is_default = true');
        DB::statement('CREATE UNIQUE INDEX market_currencies_one_default_per_market ON market_currencies (market_id) WHERE is_default = true');
        DB::statement('CREATE UNIQUE INDEX store_markets_one_default_per_store ON store_markets (store_id) WHERE is_default = true');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS store_markets_one_default_per_store');
        DB::statement('DROP INDEX IF EXISTS market_currencies_one_default_per_market');
        DB::statement('DROP INDEX IF EXISTS market_languages_one_default_per_market');
        DB::statement('DROP INDEX IF EXISTS currencies_one_global_default');
        DB::statement('DROP INDEX IF EXISTS languages_one_global_default');
    }
};
