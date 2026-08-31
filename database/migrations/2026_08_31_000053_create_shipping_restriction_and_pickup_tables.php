<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('restriction_type', 50); // product_zone, class_zone, method_zone, source_market
            $table->string('target_type', 50)->nullable(); // product, shipping_class, shipping_method, inventory_source
            $table->unsignedBigInteger('target_id')->nullable();
            $table->foreignId('shipping_zone_id')->nullable()->constrained('shipping_zones')->nullOnDelete();
            $table->foreignId('shipping_method_id')->nullable()->constrained('shipping_methods')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'restriction_type', 'target_type', 'target_id'], 'shipping_restrictions_lookup_idx');
        });

        Schema::create('pickup_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('name', 255);
            $table->foreignId('inventory_source_id')->nullable()->constrained('inventory_sources')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->bigInteger('fee_amount')->default(0); // minor units
            $table->string('currency', 3)->default('CHF');
            $table->text('instructions')->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'code'], 'pickup_locations_tenant_code_unique');
        });

        Schema::create('shipping_source_method_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('inventory_source_id')->constrained('inventory_sources')->cascadeOnDelete();
            $table->foreignId('shipping_method_id')->constrained('shipping_methods')->cascadeOnDelete();
            $table->boolean('is_allowed')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'inventory_source_id', 'shipping_method_id'], 'source_method_mapping_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_source_method_mappings');
        Schema::dropIfExists('pickup_locations');
        Schema::dropIfExists('shipping_restrictions');
    }
};
