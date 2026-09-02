<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. orders
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('order_number', 64);
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores');
            $table->foreignId('market_id')->constrained('markets');
            $table->foreignId('channel_id')->constrained('channels');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guest_token_hash', 64)->nullable();
            $table->foreignId('checkout_id')->constrained('checkout_sessions');
            $table->string('currency', 3);
            $table->string('locale', 16);
            $table->string('order_status', 32)->default('placed');
            $table->string('payment_status', 32)->default('pending');
            $table->string('fulfillment_status', 32)->default('unfulfilled');
            $table->bigInteger('merchandise_subtotal_minor');
            $table->bigInteger('discount_total_minor')->default(0);
            $table->bigInteger('shipping_total_minor')->default(0);
            $table->bigInteger('tax_total_minor')->default(0);
            $table->bigInteger('grand_total_minor');
            $table->json('customer_snapshot');
            $table->json('shipping_address_snapshot')->nullable();
            $table->json('billing_address_snapshot')->nullable();
            $table->json('pricing_snapshot')->nullable();
            $table->json('tax_snapshot')->nullable();
            $table->json('promotion_snapshot')->nullable();
            $table->json('shipping_snapshot')->nullable();
            $table->json('fulfillment_snapshot')->nullable();
            $table->json('reservation_references')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('placed_at');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'order_number'], 'uq_orders_tenant_order_number');
            $table->unique(['tenant_id', 'checkout_id'], 'uq_orders_tenant_checkout_id');
            $table->index(['tenant_id', 'user_id'], 'idx_orders_tenant_user');
            $table->index(['tenant_id', 'order_status'], 'idx_orders_tenant_status');
            $table->index(['tenant_id', 'guest_token_hash'], 'idx_orders_tenant_guest_token');
        });

        // 2. order_items
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('sku_snapshot', 128)->nullable();
            $table->string('name_snapshot', 255)->nullable();
            $table->string('product_type_snapshot', 64)->nullable();
            $table->decimal('quantity', 20, 8);
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('line_discount_minor')->default(0);
            $table->bigInteger('allocated_cart_discount_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('taxable_amount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->unsignedBigInteger('tax_class_id')->nullable();
            $table->decimal('tax_rate_percent', 8, 4)->nullable();
            $table->json('selected_options_snapshot')->nullable();
            $table->json('customization_metadata_snapshot')->nullable();
            $table->timestampsTz();

            $table->index(['order_id'], 'idx_order_items_order_id');
            $table->index(['tenant_id', 'product_id'], 'idx_order_items_tenant_product');
        });

        // 3. order_status_history
        Schema::create('order_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('status_dimension', 32);
            $table->string('from_status', 32);
            $table->string('to_status', 32);
            $table->string('reason', 255)->nullable();
            $table->string('actor_type', 32)->default('system');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['order_id', 'status_dimension'], 'idx_order_history_order_dim');
        });

        // 4. order_number_counters
        Schema::create('order_number_counters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('business_date', 8); // YYYYMMDD
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'business_date'], 'uq_order_number_counter_tenant_date');
        });

        // 5. order_operation_keys
        Schema::create('order_operation_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('checkout_id')->nullable()->constrained('checkout_sessions')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->cascadeOnDelete();
            $table->string('operation_type', 64);
            $table->string('idempotency_key', 255);
            $table->string('request_hash', 64);
            $table->string('status', 32)->default('processing');
            $table->json('response_payload')->nullable();
            $table->json('error_payload')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'operation_type', 'idempotency_key'], 'idx_order_op_lookup');
        });

        // PostgreSQL Specific Check Constraints and Partial Unique Indexes
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('
                ALTER TABLE order_operation_keys
                ADD CONSTRAINT chk_order_operation_key_target
                CHECK (
                    (checkout_id IS NOT NULL AND order_id IS NULL) OR
                    (checkout_id IS NULL AND order_id IS NOT NULL)
                )
            ');

            DB::statement('
                CREATE UNIQUE INDEX uq_order_op_checkout
                ON order_operation_keys (tenant_id, checkout_id, operation_type, idempotency_key)
                WHERE checkout_id IS NOT NULL
            ');

            DB::statement('
                CREATE UNIQUE INDEX uq_order_op_order
                ON order_operation_keys (tenant_id, order_id, operation_type, idempotency_key)
                WHERE order_id IS NOT NULL
            ');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS uq_order_op_order');
            DB::statement('DROP INDEX IF EXISTS uq_order_op_checkout');
        }

        Schema::dropIfExists('order_operation_keys');
        Schema::dropIfExists('order_number_counters');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
