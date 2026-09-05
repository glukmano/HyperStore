<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-19 Owner Delta: Affiliate bounded context. Mirrors the exact
 * conventions already established by the Marketplace vendor-payable/payout
 * schema (2026_09_03_000030_create_marketplace_tables.php) — append-only
 * payable subledger with a Postgres immutability trigger, tenant-scoped
 * composite unique/foreign keys, partial unique indexes for "at most one
 * active X" invariants — so Affiliate payables reuse the identical
 * correctness guarantees rather than a hand-rolled second design.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        // 1. affiliates
        Schema::create('affiliates', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('display_name', 150);
            $table->string('status', 32)->default('pending');
            $table->string('payout_currency', 3);
            $table->timestampTz('applied_at')->useCurrent();
            $table->timestampTz('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_affiliates_tenant_id');
            $table->foreign('tenant_id', 'fk_affiliates_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign('user_id', 'fk_affiliates_user')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('approved_by_user_id', 'fk_affiliates_approver')->references('id')->on('users')->onDelete('restrict');
            $table->index(['tenant_id', 'status']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE affiliates ADD CONSTRAINT chk_affiliates_status CHECK (status IN ('pending', 'active', 'suspended', 'rejected'))");
            // A User can be at most one Affiliate per Tenant.
            DB::statement('CREATE UNIQUE INDEX uq_affiliates_tenant_user ON affiliates (tenant_id, user_id) WHERE user_id IS NOT NULL');
        }

        // 2. affiliate_campaigns — attribution_strategy/window live HERE (Owner
        // Delta correction §8): the one authoritative configuration location.
        Schema::create('affiliate_campaigns', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 150);
            $table->string('target_type', 32)->default('platform');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('attribution_strategy', 16)->default('last_click');
            $table->unsignedInteger('attribution_window_days')->default(30);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_affiliate_campaigns_tenant_id');
            $table->foreign('tenant_id', 'fk_aff_campaigns_tenant')->references('id')->on('tenants')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE affiliate_campaigns ADD CONSTRAINT chk_aff_campaigns_target_type CHECK (target_type IN ('platform', 'store', 'vendor', 'category', 'product'))");
            DB::statement("ALTER TABLE affiliate_campaigns ADD CONSTRAINT chk_aff_campaigns_strategy CHECK (attribution_strategy IN ('first_click', 'last_click', 'coupon', 'manual'))");
            DB::statement('ALTER TABLE affiliate_campaigns ADD CONSTRAINT chk_aff_campaigns_window CHECK (attribution_window_days > 0)');
        }

        // 3. affiliate_referral_codes
        Schema::create('affiliate_referral_codes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('affiliate_id');
            $table->unsignedBigInteger('affiliate_campaign_id')->nullable();
            $table->string('code', 64);
            $table->string('target_type', 32)->default('platform');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_aff_referral_codes_tenant_id');
            // Owner Delta correction §19: Tenant-local uniqueness, not global.
            $table->unique(['tenant_id', 'code'], 'uq_aff_referral_codes_tenant_code');
            $table->foreign('tenant_id', 'fk_aff_ref_codes_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'affiliate_id'], 'fk_aff_ref_codes_affiliate')
                ->references(['tenant_id', 'id'])->on('affiliates')->onDelete('restrict');
            $table->foreign(['tenant_id', 'affiliate_campaign_id'], 'fk_aff_ref_codes_campaign')
                ->references(['tenant_id', 'id'])->on('affiliate_campaigns')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE affiliate_referral_codes ADD CONSTRAINT chk_aff_ref_codes_target_type CHECK (target_type IN ('platform', 'store', 'vendor', 'category', 'product'))");
        }

        // 4. affiliate_clicks — append-only attribution evidence. Owner Delta
        // correction §7: visitor identity is a first-party random-token HASH,
        // never a fingerprint; ip_hash/user_agent are optional fraud signals
        // only, never the visitor identity.
        Schema::create('affiliate_clicks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('affiliate_referral_code_id');
            $table->string('visitor_token_hash', 64);
            $table->string('landing_url', 2048)->nullable();
            $table->string('referer', 2048)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestampTz('clicked_at')->useCurrent();

            $table->foreign('tenant_id', 'fk_aff_clicks_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'affiliate_referral_code_id'], 'fk_aff_clicks_code')
                ->references(['tenant_id', 'id'])->on('affiliate_referral_codes')->onDelete('restrict');
            $table->index(['tenant_id', 'visitor_token_hash', 'clicked_at'], 'ix_aff_clicks_token_time');
            $table->index(['tenant_id', 'affiliate_referral_code_id', 'clicked_at'], 'ix_aff_clicks_code_time');
        });

        // 5. affiliate_attributions — the FROZEN decision (Owner Delta
        // correction §2), written once at Order-creation time and never
        // recomputed from live Click/Campaign/Rule state afterward.
        Schema::create('affiliate_attributions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('affiliate_id');
            $table->unsignedBigInteger('affiliate_referral_code_id')->nullable();
            $table->unsignedBigInteger('affiliate_campaign_id')->nullable();
            $table->string('attribution_strategy', 16);
            $table->unsignedInteger('attribution_window_days_used')->nullable();
            $table->unsignedBigInteger('attributed_click_id')->nullable();
            $table->string('visitor_token_hash', 64)->nullable();
            $table->string('target_type', 32);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->timestampTz('attributed_at')->useCurrent();
            $table->boolean('is_manual')->default(false);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('superseded_by_attribution_id')->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_aff_attributions_tenant_id');
            $table->foreign('tenant_id', 'fk_aff_attr_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'order_id'], 'fk_aff_attr_order')
                ->references(['tenant_id', 'id'])->on('orders')->onDelete('restrict');
            $table->foreign(['tenant_id', 'affiliate_id'], 'fk_aff_attr_affiliate')
                ->references(['tenant_id', 'id'])->on('affiliates')->onDelete('restrict');
            $table->foreign(['tenant_id', 'affiliate_referral_code_id'], 'fk_aff_attr_code')
                ->references(['tenant_id', 'id'])->on('affiliate_referral_codes')->onDelete('restrict');
            $table->foreign(['tenant_id', 'affiliate_campaign_id'], 'fk_aff_attr_campaign')
                ->references(['tenant_id', 'id'])->on('affiliate_campaigns')->onDelete('restrict');
            $table->foreign('created_by_user_id', 'fk_aff_attr_creator')->references('id')->on('users')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE affiliate_attributions ADD CONSTRAINT chk_aff_attr_strategy CHECK (attribution_strategy IN ('first_click', 'last_click', 'coupon', 'manual'))");
            // Owner Delta correction §19: one ACTIVE (non-superseded) attribution per Order.
            DB::statement('CREATE UNIQUE INDEX uq_aff_attr_active_per_order ON affiliate_attributions (tenant_id, order_id) WHERE superseded_by_attribution_id IS NULL');
        }

        // 6. affiliate_conversions — created at the SAME Order-creation/freeze
        // boundary as affiliate_attributions (status starts 'pending'); only
        // its status transitions to 'accrued' when payment=paid activates
        // the already-frozen commission-item snapshots. Never recomputes the
        // frozen attribution.
        Schema::create('affiliate_conversions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('affiliate_attribution_id');
            $table->unsignedBigInteger('affiliate_id');
            $table->unsignedBigInteger('order_id');
            $table->string('currency', 3);
            $table->string('status', 16)->default('pending');
            $table->timestampTz('converted_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_aff_conversions_tenant_id');
            $table->unique(['tenant_id', 'affiliate_attribution_id'], 'uq_aff_conversions_attribution');
            $table->foreign('tenant_id', 'fk_aff_conv_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'affiliate_attribution_id'], 'fk_aff_conv_attribution')
                ->references(['tenant_id', 'id'])->on('affiliate_attributions')->onDelete('restrict');
            $table->foreign(['tenant_id', 'affiliate_id'], 'fk_aff_conv_affiliate')
                ->references(['tenant_id', 'id'])->on('affiliates')->onDelete('restrict');
            $table->foreign(['tenant_id', 'order_id'], 'fk_aff_conv_order')
                ->references(['tenant_id', 'id'])->on('orders')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE affiliate_conversions ADD CONSTRAINT chk_aff_conv_status CHECK (status IN ('pending', 'accrued', 'reversed'))");
        }

        // 7. affiliate_conversion_items — line-level immutable commission
        // snapshot (Owner Delta correction §3). Never recomputed from live
        // Product/Category/Vendor/CommissionRule records afterward.
        Schema::create('affiliate_conversion_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('affiliate_conversion_id');
            $table->unsignedBigInteger('order_item_id');
            $table->string('currency', 3);
            $table->bigInteger('commissionable_base_minor');
            $table->integer('commission_rate_bps')->default(0);
            $table->bigInteger('commission_fixed_fee_minor')->default(0);
            $table->bigInteger('commission_amount_minor');
            $table->string('commission_rule_ref', 128)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'affiliate_conversion_id', 'order_item_id'], 'uq_aff_conv_items_unique');
            $table->foreign('tenant_id', 'fk_aff_conv_items_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'affiliate_conversion_id'], 'fk_aff_conv_items_conversion')
                ->references(['tenant_id', 'id'])->on('affiliate_conversions')->onDelete('restrict');
            $table->foreign('order_item_id', 'fk_aff_conv_items_order_item')->references('id')->on('order_items')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement('ALTER TABLE affiliate_conversion_items ADD CONSTRAINT chk_aff_conv_items_amounts CHECK (commissionable_base_minor >= 0 AND commission_amount_minor >= 0)');
        }

        // 8. affiliate_commission_rules (mirrors vendor_commission_rules)
        Schema::create('affiliate_commission_rules', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('affiliate_id')->nullable();
            $table->unsignedBigInteger('affiliate_campaign_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->integer('rate_basis_points');
            $table->bigInteger('fixed_fee_minor')->default(0);
            $table->string('currency', 3);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_aff_comm_rules_tenant_id');
            $table->foreign('tenant_id', 'fk_aff_comm_rules_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'affiliate_id'], 'fk_aff_comm_rules_affiliate')
                ->references(['tenant_id', 'id'])->on('affiliates')->onDelete('restrict');
            $table->foreign(['tenant_id', 'affiliate_campaign_id'], 'fk_aff_comm_rules_campaign')
                ->references(['tenant_id', 'id'])->on('affiliate_campaigns')->onDelete('restrict');
            $table->foreign('category_id', 'fk_aff_comm_rules_category')->references('id')->on('categories')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement('ALTER TABLE affiliate_commission_rules ADD CONSTRAINT chk_aff_comm_rules_rate CHECK (rate_basis_points >= 0 AND rate_basis_points <= 10000)');
            DB::statement('ALTER TABLE affiliate_commission_rules ADD CONSTRAINT chk_aff_comm_rules_fee CHECK (fixed_fee_minor >= 0)');
        }

        // 9. affiliate_payable_entries (mirrors vendor_payable_entries exactly,
        // including the append-only + immutable-economic-fields trigger)
        Schema::create('affiliate_payable_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('affiliate_id');
            $table->unsignedBigInteger('affiliate_conversion_item_id')->nullable();
            $table->string('entry_type', 32);
            $table->string('source_type', 64);
            $table->string('source_uuid', 64);
            $table->string('currency', 3);
            $table->bigInteger('amount_minor');
            $table->bigInteger('commission_amount_minor')->default(0);
            $table->bigInteger('net_amount_minor');
            $table->string('availability_status', 32)->default('pending');
            $table->timestampTz('available_at')->nullable();
            $table->string('held_reason', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_aff_payable_entries_tenant_id');
            $table->unique(['tenant_id', 'source_type', 'source_uuid', 'entry_type'], 'uq_aff_payable_entries_unique_movement');
            $table->foreign('tenant_id', 'fk_aff_payable_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'affiliate_id'], 'fk_aff_payable_affiliate')
                ->references(['tenant_id', 'id'])->on('affiliates')->onDelete('restrict');
            $table->foreign('affiliate_conversion_item_id', 'fk_aff_payable_conv_item')->references('id')->on('affiliate_conversion_items')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE affiliate_payable_entries ADD CONSTRAINT chk_aff_payable_entry_type CHECK (entry_type IN ('earning', 'refund_adjustment', 'manual_adjustment_credit', 'manual_adjustment_debit', 'payout_disbursement'))");
            DB::statement("ALTER TABLE affiliate_payable_entries ADD CONSTRAINT chk_aff_payable_avail_status CHECK (availability_status IN ('pending', 'available', 'held'))");
            DB::statement('ALTER TABLE affiliate_payable_entries ADD CONSTRAINT chk_aff_payable_amounts_positive CHECK (amount_minor > 0 AND commission_amount_minor >= 0 AND net_amount_minor >= 0)');
            DB::statement("ALTER TABLE affiliate_payable_entries ADD CONSTRAINT chk_aff_payable_disbursement_invariants CHECK ((entry_type != 'payout_disbursement') OR (commission_amount_minor = 0 AND net_amount_minor = amount_minor))");

            DB::statement("
                CREATE OR REPLACE FUNCTION trg_protect_affiliate_payable_entries()
                RETURNS TRIGGER AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Deleting rows from affiliate_payable_entries is strictly prohibited';
                    END IF;

                    IF TG_OP = 'UPDATE' THEN
                        IF NEW.tenant_id != OLD.tenant_id OR
                           NEW.affiliate_id != OLD.affiliate_id OR
                           NEW.affiliate_conversion_item_id IS DISTINCT FROM OLD.affiliate_conversion_item_id OR
                           NEW.entry_type != OLD.entry_type OR
                           NEW.source_type != OLD.source_type OR
                           NEW.source_uuid != OLD.source_uuid OR
                           NEW.currency != OLD.currency OR
                           NEW.amount_minor != OLD.amount_minor OR
                           NEW.commission_amount_minor != OLD.commission_amount_minor OR
                           NEW.net_amount_minor != OLD.net_amount_minor
                        THEN
                            RAISE EXCEPTION 'Economic fields of affiliate_payable_entries are immutable and cannot be updated';
                        END IF;
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            ");
            DB::statement('
                CREATE TRIGGER check_affiliate_payable_entries_protection
                BEFORE UPDATE OR DELETE ON affiliate_payable_entries
                FOR EACH ROW EXECUTE FUNCTION trg_protect_affiliate_payable_entries();
            ');
        }

        // 10. affiliate_payout_batches / affiliate_payout_requests /
        // affiliate_payout_request_allocations — reuse the SAME shared
        // App\Core\Payables enums for their status columns as Marketplace's
        // payout_* tables (Owner Delta correction §1: one state machine).
        Schema::create('affiliate_payout_batches', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 255);
            $table->string('status', 32)->default('draft');
            $table->string('currency', 3);
            $table->bigInteger('total_amount_minor')->default(0);
            $table->integer('item_count')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_aff_payout_batches_tenant_id');
            $table->foreign('tenant_id', 'fk_aff_payout_batches_tenant')->references('id')->on('tenants')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE affiliate_payout_batches ADD CONSTRAINT chk_aff_payout_batches_status CHECK (status IN ('draft', 'processing', 'completed', 'partially_failed'))");
        }

        Schema::create('affiliate_payout_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('affiliate_id');
            $table->unsignedBigInteger('payout_batch_id')->nullable();
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status', 32)->default('requested');
            $table->jsonb('destination_details')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_aff_payout_requests_tenant_id');
            $table->foreign('tenant_id', 'fk_aff_payout_req_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'affiliate_id'], 'fk_aff_payout_req_affiliate')
                ->references(['tenant_id', 'id'])->on('affiliates')->onDelete('restrict');
            $table->foreign(['tenant_id', 'payout_batch_id'], 'fk_aff_payout_req_batch')
                ->references(['tenant_id', 'id'])->on('affiliate_payout_batches')->onDelete('restrict');
            $table->foreign('approved_by_user_id', 'fk_aff_payout_req_approver')->references('id')->on('users')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE affiliate_payout_requests ADD CONSTRAINT chk_aff_payout_req_status CHECK (status IN ('requested', 'approved', 'processing', 'paid', 'failed', 'cancelled'))");
            DB::statement('ALTER TABLE affiliate_payout_requests ADD CONSTRAINT chk_aff_payout_req_amount CHECK (amount_minor > 0)');
        }

        Schema::create('affiliate_payout_request_allocations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('payout_request_id');
            $table->unsignedBigInteger('affiliate_payable_entry_id');
            $table->bigInteger('allocated_amount_minor');
            $table->string('status', 32)->default('reserved');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_aff_payout_allocs_tenant_id');
            $table->unique(['tenant_id', 'payout_request_id', 'affiliate_payable_entry_id'], 'uq_aff_payout_allocs_request_entry');
            $table->foreign('tenant_id', 'fk_aff_payout_allocs_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'payout_request_id'], 'fk_aff_payout_allocs_request')
                ->references(['tenant_id', 'id'])->on('affiliate_payout_requests')->onDelete('restrict');
            $table->foreign(['tenant_id', 'affiliate_payable_entry_id'], 'fk_aff_payout_allocs_entry')
                ->references(['tenant_id', 'id'])->on('affiliate_payable_entries')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE affiliate_payout_request_allocations ADD CONSTRAINT chk_aff_payout_allocs_status CHECK (status IN ('reserved', 'consumed', 'released'))");
            DB::statement('ALTER TABLE affiliate_payout_request_allocations ADD CONSTRAINT chk_aff_payout_allocs_amount CHECK (allocated_amount_minor > 0)');
        }

        // 11. affiliate_fraud_flags
        Schema::create('affiliate_fraud_flags', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('affiliate_id');
            $table->string('flag_type', 32);
            $table->timestampTz('detected_at')->useCurrent();
            $table->jsonb('details')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->string('resolution', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('tenant_id', 'fk_aff_fraud_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'affiliate_id'], 'fk_aff_fraud_affiliate')
                ->references(['tenant_id', 'id'])->on('affiliates')->onDelete('restrict');
            $table->index(['tenant_id', 'affiliate_id', 'resolved_at']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE affiliate_fraud_flags ADD CONSTRAINT chk_aff_fraud_type CHECK (flag_type IN ('self_referral', 'click_velocity_anomaly', 'conversion_velocity_anomaly', 'duplicate_fingerprint'))");
        }

        // 12. coupons.affiliate_id — additive, nullable: a Coupon may be
        // owned by an Affiliate for "coupon" attribution-strategy conversions.
        Schema::table('coupons', function (Blueprint $table): void {
            $table->unsignedBigInteger('affiliate_id')->nullable()->after('promotion_id');
        });
        if ($isPgsql) {
            DB::statement('ALTER TABLE coupons ADD CONSTRAINT fk_coupons_affiliate FOREIGN KEY (tenant_id, affiliate_id) REFERENCES affiliates (tenant_id, id) ON DELETE RESTRICT');
        }
    }

    public function down(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        if ($isPgsql) {
            DB::statement('ALTER TABLE coupons DROP CONSTRAINT IF EXISTS fk_coupons_affiliate');
        }
        Schema::table('coupons', function (Blueprint $table): void {
            $table->dropColumn('affiliate_id');
        });

        if ($isPgsql) {
            DB::statement('DROP TRIGGER IF EXISTS check_affiliate_payable_entries_protection ON affiliate_payable_entries');
            DB::statement('DROP FUNCTION IF EXISTS trg_protect_affiliate_payable_entries()');
        }

        Schema::dropIfExists('affiliate_fraud_flags');
        Schema::dropIfExists('affiliate_payout_request_allocations');
        Schema::dropIfExists('affiliate_payout_requests');
        Schema::dropIfExists('affiliate_payout_batches');
        Schema::dropIfExists('affiliate_payable_entries');
        Schema::dropIfExists('affiliate_commission_rules');
        Schema::dropIfExists('affiliate_conversion_items');
        Schema::dropIfExists('affiliate_conversions');
        Schema::dropIfExists('affiliate_attributions');
        Schema::dropIfExists('affiliate_clicks');
        Schema::dropIfExists('affiliate_referral_codes');
        Schema::dropIfExists('affiliate_campaigns');
        Schema::dropIfExists('affiliates');
    }
};
