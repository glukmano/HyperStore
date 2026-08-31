<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('inventory_source_id')->constrained('inventory_sources')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->decimal('on_hand', 14, 4)->default(0);
            $table->decimal('reserved', 14, 4)->default(0);
            $table->decimal('quarantined', 14, 4)->default(0);
            $table->decimal('damaged', 14, 4)->default(0);
            $table->decimal('incoming', 14, 4)->default(0);
            $table->decimal('low_stock_threshold', 14, 4)->nullable();
            $table->string('backorder_mode', 50)->default('deny'); // deny, allow, allow_with_limit
            $table->decimal('backorder_limit', 14, 4)->nullable();
            $table->string('tracking_mode', 50)->default('track'); // track, untracked
            $table->string('unit_of_measure_code', 50)->default('piece');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'inventory_source_id', 'product_id', 'product_variant_id'], 'stock_items_unique_source_variant');
            $table->index(['tenant_id', 'product_id', 'product_variant_id']);
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items')->cascadeOnDelete();
            $table->foreignId('inventory_source_id')->constrained('inventory_sources')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->decimal('quantity_delta', 14, 4);
            $table->decimal('resulting_on_hand', 14, 4);
            $table->string('movement_type', 50); // receive, adjustment_in, adjustment_out, reservation_commit, transfer_out, transfer_in, damaged, correction, recount
            $table->string('reference_type', 100)->nullable();
            $table->string('reference_id', 100)->nullable();
            $table->string('actor_type', 100)->nullable();
            $table->string('actor_id', 100)->nullable();
            $table->string('causation_id', 100)->nullable();
            $table->string('idempotency_key', 100)->nullable()->index();
            $table->string('reason', 255)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'stock_item_id', 'created_at']);
        });

        Schema::create('inventory_operation_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('idempotency_key', 100);
            $table->string('operation_type', 50);
            $table->string('resource_type', 100);
            $table->string('resource_id', 100)->nullable();
            $table->string('status', 30)->default('completed'); // processing, completed, failed
            $table->jsonb('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();

            $table->unique(['tenant_id', 'idempotency_key', 'operation_type'], 'inv_op_keys_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_operation_keys');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('stock_items');
    }
};
