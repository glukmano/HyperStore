<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-18 Owner Delta §5: a single global hostname-claim registry so
 * store_domains/market_domains/vendor_domains — three independent
 * per-table UNIQUE constraints — cannot collide with each other (a
 * per-table constraint only prevents a hostname being claimed twice
 * *within* that one table). Owner Delta §4: market_domains references
 * store_markets.id directly, never market_id alone, so one resolved
 * hostname always yields an unambiguous Store+Market pair — a Market
 * attached to multiple Stores never leaves the resolver guessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hostname_claims', function (Blueprint $table): void {
            $table->id();
            $table->string('normalized_hostname', 255)->unique();
            $table->string('owner_type', 40);
            $table->unsignedBigInteger('owner_id');
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('market_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_market_id')->constrained('store_markets')->cascadeOnDelete();
            $table->string('domain', 255)->unique();
            $table->boolean('is_verified')->default(false);
            $table->boolean('canonical')->default(false);
            $table->timestamps();

            $table->index('store_market_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX market_domains_one_canonical_per_context ON market_domains (store_market_id) WHERE canonical = true');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('market_domains');
        Schema::dropIfExists('hostname_claims');
    }
};
