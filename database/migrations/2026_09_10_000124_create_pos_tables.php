<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-22 POS/Omnichannel. Owner Delta §1: a Register resolves an EXACT
 * Market via an active StoreMarket relation (never a free market_id) —
 * store_market_id is required and the exact InventorySource is owned by
 * the Register itself (no ambiguous Store-level "default source").
 *
 * Owner Delta §3: the opening_float PosCashMovement row is authoritative;
 * pos_register_sessions.opening_cash_minor is an immutable snapshot of
 * that same operation, never independently editable. closing_count is
 * deliberately NOT a movement type — a physical count is not cash
 * entering/leaving the drawer, so it lives only as a closing snapshot
 * column on the session.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        Schema::create('pos_registers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('store_market_id')->constrained('store_markets')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->foreignId('inventory_source_id')->constrained('inventory_sources')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE pos_registers ADD CONSTRAINT chk_pos_registers_status CHECK (status IN ('active', 'inactive'))");
        }

        Schema::create('pos_register_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('register_id')->constrained('pos_registers')->cascadeOnDelete();
            $table->foreignId('cashier_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('active');
            $table->string('currency', 3);
            $table->bigInteger('opening_cash_minor')->default(0);
            $table->bigInteger('closing_cash_counted_minor')->nullable();
            $table->bigInteger('closing_cash_expected_minor')->nullable();
            $table->bigInteger('closing_variance_minor')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'register_id', 'status']);
            $table->index(['tenant_id', 'cashier_user_id']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE pos_register_sessions ADD CONSTRAINT chk_pos_register_sessions_status CHECK (status IN ('active', 'closed'))");
            // Owner Delta §1 / §3: exactly one active session per register, DB-enforced.
            DB::statement('CREATE UNIQUE INDEX uq_pos_register_sessions_one_active ON pos_register_sessions (register_id) WHERE status = \'active\'');
        }

        Schema::create('pos_cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('register_session_id')->constrained('pos_register_sessions')->cascadeOnDelete();
            $table->string('movement_type', 30);
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('reason', 255)->nullable();
            $table->string('source_type', 50);
            $table->string('source_uuid', 64);
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at');

            $table->unique(['tenant_id', 'source_type', 'source_uuid', 'movement_type'], 'uq_pos_cash_movement_idempotent');
            $table->index(['tenant_id', 'register_session_id']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE pos_cash_movements ADD CONSTRAINT chk_pos_cash_movement_type CHECK (movement_type IN ('opening_float', 'sale_cash_in', 'refund_cash_out', 'paid_in', 'paid_out'))");
        }

        Schema::create('pos_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('receipt_number', 40);
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('register_id')->constrained('pos_registers')->cascadeOnDelete();
            $table->foreignId('register_session_id')->constrained('pos_register_sessions')->cascadeOnDelete();
            $table->foreignId('cashier_user_id')->constrained('users')->cascadeOnDelete();
            $table->jsonb('presentation_snapshot');
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'receipt_number']);
            $table->index(['tenant_id', 'order_id']);
        });

        Schema::create('pos_manual_discount_audit_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('cart_line_id')->nullable()->constrained('cart_lines')->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->foreignId('register_session_id')->constrained('pos_register_sessions')->cascadeOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('reason', 255);
            $table->foreignId('applied_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'register_session_id']);
        });

        Schema::create('pos_cross_store_return_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->boolean('cross_store_returns_enabled')->default(true);
            $table->timestamps();

            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_cross_store_return_policies');
        Schema::dropIfExists('pos_manual_discount_audit_log');
        Schema::dropIfExists('pos_receipts');
        Schema::dropIfExists('pos_cash_movements');
        Schema::dropIfExists('pos_register_sessions');
        Schema::dropIfExists('pos_registers');
    }
};
