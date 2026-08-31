<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'code'], 'shipping_classes_tenant_code_unique');
        });

        Schema::create('package_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('name', 255);
            $table->decimal('length_cm', 10, 4)->default('0.0000');
            $table->decimal('width_cm', 10, 4)->default('0.0000');
            $table->decimal('height_cm', 10, 4)->default('0.0000');
            $table->decimal('max_weight_kg', 10, 4)->default('0.0000');
            $table->decimal('tare_weight_kg', 10, 4)->default('0.0000');
            $table->string('status', 30)->default('active');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'code'], 'package_types_tenant_code_unique');
        });

        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('rate_calculator_type', 100); // flat_rate, free_shipping, table_rate, weight_based, local_pickup, local_delivery, carrier_calculated
            $table->string('currency', 3)->default('CHF');
            $table->bigInteger('base_amount')->default(0); // minor units
            $table->bigInteger('handling_fee')->default(0); // minor units
            $table->bigInteger('min_subtotal')->nullable(); // minor units
            $table->bigInteger('max_subtotal')->nullable(); // minor units
            $table->decimal('min_weight', 14, 4)->nullable();
            $table->decimal('max_weight', 14, 4)->nullable();
            $table->integer('priority')->default(0);
            $table->string('status', 30)->default('active');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'shipping_methods_tenant_code_unique');
            $table->index(['tenant_id', 'status', 'priority'], 'shipping_methods_lookup_idx');
        });

        Schema::create('shipping_method_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_method_id')->constrained('shipping_methods')->cascadeOnDelete();
            $table->foreignId('shipping_zone_id')->constrained('shipping_zones')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['shipping_method_id', 'shipping_zone_id'], 'shipping_method_zones_unique');
        });

        Schema::create('shipping_rate_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_method_id')->constrained('shipping_methods')->cascadeOnDelete();
            $table->integer('priority')->default(0);
            $table->string('condition_type', 100); // weight_range, subtotal_range, quantity_range, shipping_class
            $table->jsonb('conditions_payload');
            $table->string('action_type', 100); // fixed_amount, per_item, per_weight_step, per_package
            $table->jsonb('action_payload');
            $table->boolean('stop_processing')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['shipping_method_id', 'priority'], 'shipping_rate_rules_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rate_rules');
        Schema::dropIfExists('shipping_method_zones');
        Schema::dropIfExists('shipping_methods');
        Schema::dropIfExists('package_types');
        Schema::dropIfExists('shipping_classes');
    }
};
