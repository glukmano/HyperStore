<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('name', 255);
            $table->integer('priority')->default(0);
            $table->string('status', 30)->default('active');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'shipping_zones_tenant_code_unique');
            $table->index(['tenant_id', 'status', 'priority'], 'shipping_zones_lookup_idx');
        });

        Schema::create('shipping_zone_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_zone_id')->constrained('shipping_zones')->cascadeOnDelete();
            $table->string('rule_type', 50); // country, region, postal_exact, postal_prefix, postal_range, broad_global
            $table->string('country_code', 2)->nullable();
            $table->string('region_code', 20)->nullable();
            $table->string('postal_code_pattern', 50)->nullable();
            $table->string('postal_code_range_start', 50)->nullable();
            $table->string('postal_code_range_end', 50)->nullable();
            $table->boolean('is_exclusion')->default(false);
            $table->integer('priority')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['shipping_zone_id', 'rule_type'], 'shipping_zone_rules_type_idx');
            $table->index(['country_code', 'region_code'], 'shipping_zone_rules_geo_idx');
        });

        Schema::create('shipping_zone_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_zone_id')->constrained('shipping_zones')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('market_id')->nullable()->constrained('markets')->nullOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained('channels')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['shipping_zone_id', 'store_id', 'market_id', 'channel_id'], 'shipping_zone_assignments_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_zone_assignments');
        Schema::dropIfExists('shipping_zone_rules');
        Schema::dropIfExists('shipping_zones');
    }
};
