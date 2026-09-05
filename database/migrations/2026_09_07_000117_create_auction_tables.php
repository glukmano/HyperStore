<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-20 Auctions. Owner Delta §5: `bids` is genuinely append-only — no
 * `status` column, never updated after insert. The current winner is derived
 * solely from `auctions.current_bid_id` (added via a separate ALTER after
 * `bids` exists, resolving the circular FK). Owner Delta §6: bid/close
 * comparisons use database-authoritative time. Owner Delta §7: an
 * eligibility gate + inventory-reservation-at-activation policy are
 * enforced in application code against `auctions.inventory_reservation_key`.
 * Owner Delta §8: `winner_payment_window_minutes` +
 * `unpaid_winner_policy` are explicit configuration, not undefined.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        Schema::create('auctions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('currency', 3);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->bigInteger('reserve_price_minor')->nullable();
            $table->bigInteger('starting_price_minor');
            $table->bigInteger('bid_increment_minor');
            $table->string('status', 30)->default('scheduled');
            $table->bigInteger('current_price_minor')->nullable();
            $table->unsignedBigInteger('current_bidder_customer_profile_id')->nullable();
            $table->unsignedInteger('winner_payment_window_minutes')->default(1440);
            $table->string('unpaid_winner_policy', 30)->default('cancel');
            $table->string('inventory_reservation_key', 191)->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'ends_at']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE auctions ADD CONSTRAINT chk_auctions_status CHECK (status IN ('scheduled', 'active', 'ended', 'settled', 'cancelled'))");
            DB::statement("ALTER TABLE auctions ADD CONSTRAINT chk_auctions_unpaid_policy CHECK (unpaid_winner_policy IN ('cancel'))");
            DB::statement('ALTER TABLE auctions ADD CONSTRAINT chk_auctions_prices_positive CHECK (starting_price_minor > 0 AND bid_increment_minor > 0)');
        }

        // Owner Delta §5: append-only, immutable — no status column, never
        // updated. `placed_at` is DB-authoritative time (Owner Delta §6),
        // written once at insert from inside the same locked transaction
        // that repoints Auction.current_bid_id.
        Schema::create('bids', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('auction_id')->constrained('auctions')->cascadeOnDelete();
            $table->foreignId('customer_profile_id')->constrained('customer_profiles')->cascadeOnDelete();
            $table->bigInteger('amount_minor');
            $table->timestampTz('placed_at');

            $table->index(['auction_id', 'placed_at']);
        });
        if ($isPgsql) {
            DB::statement('ALTER TABLE bids ADD CONSTRAINT chk_bids_amount_positive CHECK (amount_minor > 0)');
            // Defense-in-depth immutability, matching every other
            // append-only financial/economic table's convention platform-wide.
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_bid_mutation() RETURNS trigger AS $$
                BEGIN
                    IF (TG_OP = 'UPDATE') THEN
                        RAISE EXCEPTION 'bids rows are immutable and may never be updated (id=%)', OLD.id;
                    ELSIF (TG_OP = 'DELETE') THEN
                        RAISE EXCEPTION 'bids rows are immutable and may never be deleted (id=%)', OLD.id;
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            DB::statement('CREATE TRIGGER trg_bids_immutable BEFORE UPDATE OR DELETE ON bids FOR EACH ROW EXECUTE FUNCTION prevent_bid_mutation()');
        }

        Schema::table('auctions', function (Blueprint $table): void {
            $table->foreignId('current_bid_id')->nullable()->after('current_price_minor')->constrained('bids')->nullOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('auction_id')->nullable()->after('quote_line_id');
            $table->unsignedBigInteger('winning_bid_id')->nullable()->after('auction_id');
            $table->bigInteger('winning_bid_amount_minor')->nullable()->after('winning_bid_id');
            $table->string('auction_currency_snapshot', 3)->nullable()->after('winning_bid_amount_minor');
            $table->boolean('reserve_met')->nullable()->after('auction_currency_snapshot');
        });

        // Owner Delta §7: marks the system-generated winner Checkout session
        // as bound to exactly one Auction/winning bidder, so
        // CheckoutOrchestrator::reserveInventory() knows to hand off the
        // Auction's own already-held Inventory reservation instead of
        // reserving a second time, and so ownership/reuse-rejection can be
        // enforced via the existing CheckoutOwnershipService.
        Schema::table('checkout_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('auction_id')->nullable()->after('promotion_snapshot');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_bids_immutable ON bids');
            DB::statement('DROP FUNCTION IF EXISTS prevent_bid_mutation()');
        }

        Schema::table('checkout_sessions', function (Blueprint $table): void {
            $table->dropColumn('auction_id');
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['auction_id', 'winning_bid_id', 'winning_bid_amount_minor', 'auction_currency_snapshot', 'reserve_met']);
        });
        Schema::table('auctions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_bid_id');
        });
        Schema::dropIfExists('bids');
        Schema::dropIfExists('auctions');
    }
};
