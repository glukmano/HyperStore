<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-21 Store Value: one shared engine, typed instruments (Owner Delta
 * §15) — StoreValueAccount/StoreValueEntry back Wallet, Store Credit, AND
 * Gift Card (modules/GiftCards delegates here). Hold->capture/release
 * checkout lifecycle (Owner Delta §16): only issue/capture/refund_credit/
 * expire/manual_adjustment_* ever post to Ledger — hold/release never do.
 * order_payment_tender_allocations is the authoritative record of what
 * paid for what (D.50), snapshotted once at Payment-capture time.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        // Owner Delta §15: customer_profile_id is nullable — a Gift Card may
        // exist and be spent under guest checkout before any Customer has
        // claimed it. instrument_type is set from the base model onward,
        // never bolted on — a redeemed Gift Card gets its OWN account row,
        // never merged into a Customer's Wallet/Store Credit account.
        Schema::create('store_value_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_profile_id')->nullable()->constrained('customer_profiles')->nullOnDelete();
            $table->string('instrument_type', 20);
            $table->string('currency', 3);
            $table->string('scope', 20)->default('tenant');
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'customer_profile_id', 'instrument_type']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE store_value_accounts ADD CONSTRAINT chk_store_value_accounts_instrument CHECK (instrument_type IN ('wallet', 'store_credit', 'gift_card'))");
            DB::statement("ALTER TABLE store_value_accounts ADD CONSTRAINT chk_store_value_accounts_status CHECK (status IN ('active', 'deactivated'))");
        }

        Schema::create('store_value_account_locks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('store_value_account_id')->constrained('store_value_accounts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique('store_value_account_id', 'uq_store_value_lock_account');
        });

        // Owner Delta §16/§17: hold/release never post to Ledger — only
        // issue/capture/refund_credit/expire/manual_adjustment_* do, each
        // atomically with its JournalEntry (see ADR-0150). A capture/release
        // MUST reference the hold entry it resolves via reverses_entry_id.
        Schema::create('store_value_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('store_value_account_id')->constrained('store_value_accounts')->cascadeOnDelete();
            $table->string('instrument_type', 20);
            $table->string('entry_type', 24);
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('source_type', 64);
            $table->string('source_uuid', 64);
            $table->foreignId('reverses_entry_id')->nullable()->constrained('store_value_entries')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'source_type', 'source_uuid', 'entry_type'], 'uq_store_value_entries_idem');
            $table->index(['store_value_account_id', 'created_at']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE store_value_entries ADD CONSTRAINT chk_store_value_entries_type CHECK (entry_type IN ('issue', 'hold', 'capture', 'release', 'refund_credit', 'expire', 'manual_adjustment_credit', 'manual_adjustment_debit'))");
            // A hold is resolved EXACTLY ONCE, by either capture OR release,
            // never both — same discipline as B2B's credit-entry model.
            DB::statement('CREATE UNIQUE INDEX uq_store_value_entries_one_resolution ON store_value_entries (reverses_entry_id) WHERE entry_type IN (\'capture\', \'release\')');
        }

        Schema::create('order_payment_tender_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('tender_type', 20);
            $table->string('source_reference', 128);
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['order_id', 'tender_type', 'source_reference'], 'uq_order_tender_alloc');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE order_payment_tender_allocations ADD CONSTRAINT chk_order_tender_alloc_type CHECK (tender_type IN ('external_gateway', 'wallet', 'store_credit', 'gift_card'))");
        }

        // Owner Delta D.50: the computed refund allocation is itself
        // snapshotted at computation time, keyed by refund_event_uuid — a
        // retried/duplicate refund reuses this exact frozen allocation
        // rather than recomputing (which could differ if tender allocations
        // were re-read in a different order).
        Schema::create('refund_tender_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('refund_event_uuid', 64);
            $table->string('tender_type', 20);
            $table->string('source_reference', 128)->nullable();
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['order_id', 'refund_event_uuid', 'tender_type'], 'uq_refund_tender_alloc');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE refund_tender_allocations ADD CONSTRAINT chk_refund_tender_alloc_type CHECK (tender_type IN ('external_gateway', 'wallet', 'store_credit', 'gift_card'))");
        }

        // Owner Delta §16: applying Store Value at Checkout is a hold — the
        // amount is tracked on the CheckoutSession itself, never a Coupon.
        Schema::table('checkout_sessions', function (Blueprint $table): void {
            $table->bigInteger('store_value_applied_minor')->default(0)->after('reservation_references');
            $table->jsonb('store_value_hold_refs')->nullable()->after('store_value_applied_minor');
        });

        // C.25/C.27: amountDueMinor — equal to grand_total_minor when no
        // Store Value was applied (zero behavior change for every existing
        // Order). Frozen at Order-creation time from the CheckoutSession's
        // authoritative pricing pass.
        Schema::table('orders', function (Blueprint $table): void {
            $table->bigInteger('amount_due_minor')->nullable()->after('grand_total_minor');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('amount_due_minor');
        });
        Schema::table('checkout_sessions', function (Blueprint $table): void {
            $table->dropColumn(['store_value_applied_minor', 'store_value_hold_refs']);
        });
        Schema::dropIfExists('refund_tender_allocations');
        Schema::dropIfExists('order_payment_tender_allocations');
        Schema::dropIfExists('store_value_entries');
        Schema::dropIfExists('store_value_account_locks');
        Schema::dropIfExists('store_value_accounts');
    }
};
