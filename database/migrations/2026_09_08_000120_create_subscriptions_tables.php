<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-21 Subscriptions: SubscriptionPlan/Subscription reuse the existing
 * Pricing/Checkout/Order pipeline (D.39/D.40/D.42 — no second Order engine,
 * no second price source). SubscriptionRenewalAttempt is itself the
 * PostgreSQL-authoritative claim mechanism — one row per billing period is
 * created BEFORE any charge attempt, via the unique constraint below
 * (Owner Delta §11). CustomerPaymentMethod stores only an opaque provider
 * reference, never raw card data (Owner Delta §10).
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        Schema::create('customer_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_profile_id')->constrained('customer_profiles')->cascadeOnDelete();
            $table->string('gateway_provider_code', 64);
            $table->string('gateway_reference', 255);
            $table->string('display_brand', 32)->nullable();
            $table->string('display_last4', 4)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['customer_profile_id', 'is_default']);
        });

        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('billing_interval', 20);
            $table->unsignedInteger('billing_interval_days')->nullable();
            $table->unsignedInteger('trial_days')->nullable();
            $table->json('dunning_retry_days')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'product_id']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE subscription_plans ADD CONSTRAINT chk_subscription_plans_interval CHECK (billing_interval IN ('monthly', 'yearly', 'custom_days'))");
        }

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_profile_id')->constrained('customer_profiles')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->foreignId('pending_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            // Frozen Store/Market/Channel context from the originating
            // purchase Order — the unattended renewal command builds its
            // synthetic Cart in the SAME commerce context every period,
            // never re-resolving it from a live request.
            $table->foreignId('store_id')->constrained('stores');
            $table->foreignId('market_id')->constrained('markets');
            $table->foreignId('channel_id')->constrained('channels');
            $table->string('status', 20)->default('trialing');
            $table->timestampTz('current_period_start');
            $table->timestampTz('current_period_end');
            $table->timestampTz('next_billing_at');
            $table->boolean('cancel_at_period_end')->default(false);
            $table->foreignId('payment_method_id')->nullable()->constrained('customer_payment_methods')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('next_billing_at');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT chk_subscriptions_status CHECK (status IN ('trialing', 'active', 'past_due', 'grace', 'suspended', 'cancelled'))");
        }

        // Owner Delta §11: the claim happens via this UNIQUE constraint,
        // enforced from the FIRST attempt of ANY outcome — not merely
        // successful ones. A worker's very first step is an INSERT that
        // either creates this row (it now owns the period) or conflicts
        // (another worker already owns it, so this worker stops immediately
        // — before building a Cart, before calling the provider).
        Schema::create('subscription_renewal_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->timestampTz('billing_period_start');
            $table->string('status', 20)->default('claimed');
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('provider_idempotency_key', 128);
            $table->text('failure_reason')->nullable();
            $table->timestampTz('claimed_at');
            $table->timestampTz('resolved_at')->nullable();

            $table->unique(['subscription_id', 'billing_period_start'], 'uq_subscription_renewal_period');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE subscription_renewal_attempts ADD CONSTRAINT chk_subscription_renewal_status CHECK (status IN ('claimed', 'succeeded', 'failed', 'unknown'))");
        }

        // Order-time snapshots: a later plan price/name change never alters
        // a historical renewal Order's frozen facts.
        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('subscription_id')->nullable()->after('entitlement_terms_snapshot');
            $table->timestampTz('billing_period_start_snapshot')->nullable()->after('subscription_id');
            $table->timestampTz('billing_period_end_snapshot')->nullable()->after('billing_period_start_snapshot');
            $table->json('plan_snapshot')->nullable()->after('billing_period_end_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['subscription_id', 'billing_period_start_snapshot', 'billing_period_end_snapshot', 'plan_snapshot']);
        });
        Schema::dropIfExists('subscription_renewal_attempts');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_plans');
        Schema::dropIfExists('customer_payment_methods');
    }
};
