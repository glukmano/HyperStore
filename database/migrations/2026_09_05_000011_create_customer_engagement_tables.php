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
        $isPgsql = DB::getDriverName() === 'pgsql';

        // ── Wishlists ──────────────────────────────────────────────────────────
        // Guest wishlists are modeled the same guest-vs-auth way as
        // recently_viewed_items: an XOR identity (user_id OR session_id, never
        // both/neither) plus a partial unique index per identity so at most
        // one row can be flagged is_default for a given user or session.
        Schema::create('wishlists', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('session_id', 100)->nullable();
            $table->string('name', 150)->default('Default');
            $table->boolean('is_default')->default(true);
            $table->string('visibility', 20)->default('private');
            $table->string('share_token', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'session_id']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE wishlists ADD CONSTRAINT chk_wishlists_visibility CHECK (visibility IN ('private', 'shared'))");
            DB::statement('ALTER TABLE wishlists ADD CONSTRAINT chk_wishlists_identity_xor CHECK ((user_id IS NOT NULL) <> (session_id IS NOT NULL))');
            DB::statement('CREATE UNIQUE INDEX uq_wishlists_default_user ON wishlists (tenant_id, user_id) WHERE user_id IS NOT NULL AND is_default = true');
            DB::statement('CREATE UNIQUE INDEX uq_wishlists_default_session ON wishlists (tenant_id, session_id) WHERE session_id IS NOT NULL AND is_default = true');
        }

        Schema::create('wishlist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wishlist_id')->constrained('wishlists')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('added_at')->useCurrent();
        });
        if ($isPgsql) {
            DB::statement('CREATE UNIQUE INDEX uq_wishlist_items_product ON wishlist_items (wishlist_id, product_id, COALESCE(variant_id, 0))');
        } else {
            $table = 'wishlist_items';
            Schema::table($table, function (Blueprint $table): void {
                $table->unique(['wishlist_id', 'product_id', 'variant_id']);
            });
        }

        // ── Recently Viewed ────────────────────────────────────────────────────
        Schema::create('recently_viewed_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('session_id', 100)->nullable();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamp('viewed_at')->useCurrent();
            $table->unsignedInteger('view_count')->default(1);
        });
        if ($isPgsql) {
            DB::statement('ALTER TABLE recently_viewed_items ADD CONSTRAINT chk_recently_viewed_identity_xor CHECK ((user_id IS NOT NULL) <> (session_id IS NOT NULL))');
            DB::statement('CREATE UNIQUE INDEX uq_recently_viewed_user ON recently_viewed_items (tenant_id, user_id, product_id) WHERE user_id IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX uq_recently_viewed_session ON recently_viewed_items (tenant_id, session_id, product_id) WHERE session_id IS NOT NULL');
        } else {
            Schema::table('recently_viewed_items', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'user_id', 'product_id']);
                $table->unique(['tenant_id', 'session_id', 'product_id']);
            });
        }

        // ── Save For Later ─────────────────────────────────────────────────────
        Schema::create('saved_for_later_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->integer('unit_price_minor_snapshot');
            $table->string('currency', 3);
            $table->timestamp('added_at')->useCurrent();

            $table->index(['tenant_id', 'user_id']);
        });

        // ── Follow ─────────────────────────────────────────────────────────────
        Schema::create('product_follows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'user_id', 'product_id']);
        });

        Schema::create('vendor_follows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'user_id', 'vendor_id']);
        });

        // ── Price Drop / Back-in-Stock Alert Subscriptions ────────────────────
        Schema::create('price_drop_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->integer('target_price_minor')->nullable();
            $table->string('currency', 3);
            $table->integer('baseline_price_minor');
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained('channels')->nullOnDelete();
            $table->foreignId('market_id')->nullable()->constrained('markets')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        if ($isPgsql) {
            DB::statement('CREATE UNIQUE INDEX uq_price_drop_subs ON price_drop_subscriptions (tenant_id, user_id, product_id, COALESCE(variant_id, 0))');
        } else {
            Schema::table('price_drop_subscriptions', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'user_id', 'product_id', 'variant_id']);
            });
        }

        Schema::create('back_in_stock_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        if ($isPgsql) {
            DB::statement('CREATE UNIQUE INDEX uq_back_in_stock_subs ON back_in_stock_subscriptions (tenant_id, user_id, product_id, COALESCE(variant_id, 0), store_id)');
        } else {
            Schema::table('back_in_stock_subscriptions', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'user_id', 'product_id', 'variant_id', 'store_id']);
            });
        }

        // ── Gift Registry ──────────────────────────────────────────────────────
        Schema::create('gift_registries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 150);
            $table->string('event_type', 20)->default('other');
            $table->date('event_date')->nullable();
            $table->string('visibility', 20)->default('unlisted');
            $table->string('share_token', 64)->unique();
            $table->jsonb('shipping_address')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE gift_registries ADD CONSTRAINT chk_gift_registries_event_type CHECK (event_type IN ('wedding', 'baby', 'birthday', 'other'))");
            DB::statement("ALTER TABLE gift_registries ADD CONSTRAINT chk_gift_registries_visibility CHECK (visibility IN ('private', 'unlisted', 'public'))");
        }

        Schema::create('gift_registry_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('registry_id')->constrained('gift_registries')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedInteger('quantity_requested')->default(1);
            $table->unsignedInteger('quantity_purchased')->default(0);
            $table->string('priority', 10)->default('medium');
            $table->text('note')->nullable();
            $table->timestamps();
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE gift_registry_items ADD CONSTRAINT chk_gift_registry_items_priority CHECK (priority IN ('low', 'medium', 'high'))");
            DB::statement('ALTER TABLE gift_registry_items ADD CONSTRAINT chk_gift_registry_items_qty CHECK (quantity_purchased <= quantity_requested)');
        }

        Schema::create('gift_registry_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('registry_item_id')->constrained('gift_registry_items')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('purchaser_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamp('purchased_at')->useCurrent();

            // One OrderItem can only ever fulfil one gift-registry purchase
            // record — this is what makes RecordGiftRegistryPurchasesOnOrderCompletion
            // idempotent against a duplicate OrderStatusChanged delivery.
            $table->unique('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_registry_purchases');
        Schema::dropIfExists('gift_registry_items');
        Schema::dropIfExists('gift_registries');
        Schema::dropIfExists('back_in_stock_subscriptions');
        Schema::dropIfExists('price_drop_subscriptions');
        Schema::dropIfExists('vendor_follows');
        Schema::dropIfExists('product_follows');
        Schema::dropIfExists('saved_for_later_items');
        Schema::dropIfExists('recently_viewed_items');
        Schema::dropIfExists('wishlist_items');
        Schema::dropIfExists('wishlists');
    }
};
