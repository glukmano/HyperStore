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
        Schema::create('checkout_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guest_token_hash', 64)->nullable()->index();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->string('currency', 3);
            $table->string('locale', 10)->default('en');
            $table->string('state', 30)->default('created');
            $table->jsonb('customer_data')->nullable();
            $table->jsonb('shipping_address')->nullable();
            $table->jsonb('billing_address')->nullable();
            $table->jsonb('selected_shipping_quote')->nullable();
            $table->jsonb('pricing_snapshot')->nullable();
            $table->jsonb('tax_snapshot')->nullable();
            $table->jsonb('promotion_snapshot')->nullable();
            $table->jsonb('reservation_references')->nullable();
            $table->jsonb('ready_snapshot')->nullable();
            $table->unsignedInteger('evaluated_cart_version')->default(1);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['tenant_id', 'state']);
        });

        // Partial unique index for active CheckoutSession per Cart (only 1 non-terminal checkout per cart)
        DB::statement("
            CREATE UNIQUE INDEX unique_active_cart_checkout 
            ON checkout_sessions (tenant_id, cart_id) 
            WHERE state NOT IN ('ready_for_order', 'expired', 'cancelled', 'failed');
        ");

        Schema::create('checkout_operation_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('cart_id')->nullable()->constrained('carts')->cascadeOnDelete();
            $table->foreignId('checkout_session_id')->nullable()->constrained('checkout_sessions')->cascadeOnDelete();
            $table->string('operation_type', 50);
            $table->string('idempotency_key', 128);
            $table->string('request_fingerprint', 64);
            $table->string('status', 20)->default('processing'); // processing, completed, failed
            $table->jsonb('response_payload')->nullable();
            $table->jsonb('error_payload')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'operation_type', 'status']);
        });

        // CHECK constraint: exactly one of cart_id or checkout_session_id must be populated
        DB::statement('
            ALTER TABLE checkout_operation_keys 
            ADD CONSTRAINT chk_checkout_op_exclusive_scope 
            CHECK (
                (cart_id IS NOT NULL AND checkout_session_id IS NULL)
                OR
                (cart_id IS NULL AND checkout_session_id IS NOT NULL)
            );
        ');

        // Partial unique index for Cart-scoped operations (e.g. create_checkout)
        DB::statement('
            CREATE UNIQUE INDEX unique_op_key_cart_scope 
            ON checkout_operation_keys (tenant_id, cart_id, operation_type, idempotency_key) 
            WHERE cart_id IS NOT NULL AND checkout_session_id IS NULL;
        ');

        // Partial unique index for Checkout-scoped operations (e.g. reserve, ready, shipping_selection)
        DB::statement('
            CREATE UNIQUE INDEX unique_op_key_checkout_scope 
            ON checkout_operation_keys (tenant_id, checkout_session_id, operation_type, idempotency_key) 
            WHERE checkout_session_id IS NOT NULL AND cart_id IS NULL;
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_operation_keys');
        Schema::dropIfExists('checkout_sessions');
    }
};
