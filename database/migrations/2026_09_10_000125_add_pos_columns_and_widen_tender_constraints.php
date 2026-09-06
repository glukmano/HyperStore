<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-22 additive columns + widened tender_type CHECK constraints.
 * Owner Delta §2: pos_register_id/pos_register_session_id/cashier_user_id
 * are operational/audit-critical facts frozen atomically with Order
 * creation (via a hard-fail hook), never a soft/optional snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('pos_register_id')->nullable()->after('channel_id')->constrained('pos_registers')->nullOnDelete();
            $table->foreignId('pos_register_session_id')->nullable()->after('pos_register_id')->constrained('pos_register_sessions')->nullOnDelete();
            $table->foreignId('cashier_user_id')->nullable()->after('pos_register_session_id')->constrained('users')->nullOnDelete();
            $table->string('receipt_number', 40)->nullable()->after('cashier_user_id');
            $table->foreignId('pickup_location_id')->nullable()->after('receipt_number')->constrained('pickup_locations')->nullOnDelete();
        });

        Schema::table('checkout_sessions', function (Blueprint $table): void {
            $table->jsonb('pos_context_snapshot')->nullable()->after('fulfillment_snapshot');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->bigInteger('pos_manual_discount_minor')->nullable()->after('discount_minor');
            $table->string('pos_manual_discount_reason', 255)->nullable()->after('pos_manual_discount_minor');
            $table->foreignId('pos_manual_discount_applied_by_user_id')->nullable()->after('pos_manual_discount_reason')->constrained('users')->nullOnDelete();
        });

        if ($isPgsql) {
            // Owner Delta §6/§4: cash is a genuine tender type, additive widening only.
            DB::statement('ALTER TABLE order_payment_tender_allocations DROP CONSTRAINT chk_order_tender_alloc_type');
            DB::statement("ALTER TABLE order_payment_tender_allocations ADD CONSTRAINT chk_order_tender_alloc_type CHECK (tender_type IN ('external_gateway', 'wallet', 'store_credit', 'gift_card', 'cash'))");

            DB::statement('ALTER TABLE refund_tender_allocations DROP CONSTRAINT chk_refund_tender_alloc_type');
            DB::statement("ALTER TABLE refund_tender_allocations ADD CONSTRAINT chk_refund_tender_alloc_type CHECK (tender_type IN ('external_gateway', 'wallet', 'store_credit', 'gift_card', 'cash'))");
        }
    }

    public function down(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        if ($isPgsql) {
            DB::statement('ALTER TABLE refund_tender_allocations DROP CONSTRAINT chk_refund_tender_alloc_type');
            DB::statement("ALTER TABLE refund_tender_allocations ADD CONSTRAINT chk_refund_tender_alloc_type CHECK (tender_type IN ('external_gateway', 'wallet', 'store_credit', 'gift_card'))");

            DB::statement('ALTER TABLE order_payment_tender_allocations DROP CONSTRAINT chk_order_tender_alloc_type');
            DB::statement("ALTER TABLE order_payment_tender_allocations ADD CONSTRAINT chk_order_tender_alloc_type CHECK (tender_type IN ('external_gateway', 'wallet', 'store_credit', 'gift_card'))");
        }

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pos_manual_discount_applied_by_user_id');
            $table->dropColumn(['pos_manual_discount_minor', 'pos_manual_discount_reason']);
        });

        Schema::table('checkout_sessions', function (Blueprint $table): void {
            $table->dropColumn('pos_context_snapshot');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pickup_location_id');
            $table->dropColumn('receipt_number');
            $table->dropConstrainedForeignId('cashier_user_id');
            $table->dropConstrainedForeignId('pos_register_session_id');
            $table->dropConstrainedForeignId('pos_register_id');
        });
    }
};
