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
        // 1. payments
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('status', 32);
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->unsignedBigInteger('authorized_amount_minor')->default(0);
            $table->unsignedBigInteger('captured_amount_minor')->default(0);
            $table->unsignedBigInteger('refunded_amount_minor')->default(0);
            $table->timestampTz('captured_at')->nullable();
            $table->timestampTz('authorized_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'order_id'], 'uq_payments_tenant_order');
            $table->index(['tenant_id', 'status'], 'idx_payments_tenant_status');
        });

        // 2. payment_operation_keys
        Schema::create('payment_operation_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('idempotency_key', 255);
            $table->string('operation_type', 64);
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('request_hash', 64);
            $table->jsonb('response_payload')->nullable();
            $table->jsonb('error_payload')->nullable();
            $table->string('status', 32);
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'order_id'], 'idx_payment_op_keys_tenant_order');
        });

        // Partial unique indexes for payment_operation_keys
        DB::statement("
            CREATE UNIQUE INDEX uq_payment_op_keys_order 
            ON payment_operation_keys (tenant_id, order_id, operation_type, idempotency_key)
            WHERE operation_type = 'initiate_payment'
        ");

        DB::statement('
            CREATE UNIQUE INDEX uq_payment_op_keys_payment 
            ON payment_operation_keys (tenant_id, payment_id, operation_type, idempotency_key)
            WHERE payment_id IS NOT NULL
        ');

        // 3. payment_transactions
        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('payment_operation_key_id')->nullable()->constrained('payment_operation_keys')->nullOnDelete();
            $table->string('operation_type', 32);
            $table->string('status', 32);
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('provider_code', 64)->nullable();
            $table->string('payment_method_type', 64)->nullable();
            $table->string('provider_reference', 255)->nullable();
            $table->string('provider_idempotency_key', 255)->nullable();
            $table->string('provider_response_code', 64)->nullable();
            $table->string('normalized_error_code', 64)->nullable();
            $table->string('action_type', 64)->nullable();
            $table->jsonb('action_payload')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'payment_id', 'status'], 'idx_payment_transactions_lookup');
        });

        // Partial unique index preventing duplicate transactions for the same operation key
        DB::statement('
            CREATE UNIQUE INDEX uq_payment_tx_op_key 
            ON payment_transactions (payment_operation_key_id)
            WHERE payment_operation_key_id IS NOT NULL
        ');

        // Partial unique index preventing duplicate provider idempotency executions
        DB::statement('
            CREATE UNIQUE INDEX uq_payment_tx_provider_idemp 
            ON payment_transactions (tenant_id, provider_code, provider_idempotency_key)
            WHERE provider_idempotency_key IS NOT NULL
        ');

        // PostgreSQL-specific CHECK constraints
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_amount CHECK (amount_minor >= 0)');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_auth CHECK (authorized_amount_minor >= 0)');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_captured CHECK (captured_amount_minor >= 0 AND captured_amount_minor <= amount_minor)');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_refunded CHECK (refunded_amount_minor >= 0 AND refunded_amount_minor <= captured_amount_minor)');
            DB::statement('ALTER TABLE payment_transactions ADD CONSTRAINT chk_payment_tx_amount CHECK (amount_minor >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payment_operation_keys');
        Schema::dropIfExists('payments');
    }
};
